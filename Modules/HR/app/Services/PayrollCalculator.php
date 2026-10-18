<?php

namespace Modules\HR\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\HR\Models\Attendance;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\TimePermission;
use Modules\HR\Support\ScheduleResolver;
use Modules\HR\Support\WorkCalendar;

/**
 * One employee's pay for one month, worked out from what actually happened.
 *
 * The salary is a fixed monthly amount (basic + allowances; prorated for someone
 * who joined or left mid-month). A day is 1/30 of it, an hour is a day divided
 * by the schedule's hours. Each day of the month is then classified:
 *  - weekend (the schedule's) or company holiday: paid, no effect;
 *  - an attendance record: present. The time missed against the schedule
 *    (late arrival + early leave + any gap) is the day's shortfall; minutes covered
 *    by an approved time permission are excused, and the schedule's grace
 *    allowance is forgiven once; the rest is deducted at the hourly rate. A day
 *    checked in but never checked out is "incomplete" and deducts nothing;
 *  - no record but an approved leave: paid leave (annual / sick / other) costs
 *    nothing, unpaid leave costs a day;
 *  - otherwise: absent, which costs a day.
 * Overtime (minutes past the schedule's end) is paid at the hourly rate times the
 * overtime multiplier, for employees marked overtime-eligible only. Then insurance
 * (a percentage of basic) and tax (a percentage of what is left) are withheld.
 */
class PayrollCalculator
{
    public function __construct(private readonly ScheduleResolver $schedules) {}

    /**
     * @return array<string, mixed>|null The payslip's columns, or null if the employee wasn't employed in the period.
     */
    public function calculate(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd, WorkCalendar $calendar): ?array
    {
        $from = Carbon::parse($employee->hire_date)->gt($periodStart) ? Carbon::parse($employee->hire_date) : Carbon::parse($periodStart);
        $to = $employee->termination_date !== null && Carbon::parse($employee->termination_date)->lt($periodEnd) ? Carbon::parse($employee->termination_date) : Carbon::parse($periodEnd);

        if ($from->gt($to)) {
            return null;
        }

        $schedule = $this->schedules->forEmployee($employee);
        $scheduledMinutes = $schedule->scheduledMinutes();
        $grace = $schedule->grace_minutes;

        $attendances = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (Attendance $a) => $a->work_date->toDateString());

        $leaves = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get();

        $excusedByDay = TimePermission::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('permission_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn (TimePermission $p) => $p->permission_date->toDateString())
            ->map(fn ($group) => (int) $group->sum('minutes'));

        $stats = array_fill_keys([
            'working_days', 'weekend_days', 'holiday_days', 'present_days', 'absent_days', 'paid_leave_days', 'unpaid_leave_days',
            'incomplete_days', 'late_minutes', 'early_leave_minutes', 'shortfall_minutes', 'excused_minutes', 'unexcused_minutes', 'overtime_minutes',
        ], 0);

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();

            if ($schedule->isWeekend($day)) {
                $stats['weekend_days']++;

                continue;
            }

            if ($calendar->isHoliday($day)) {
                $stats['holiday_days']++;

                continue;
            }

            $stats['working_days']++;
            $attendance = $attendances->get($date);

            if ($attendance !== null) {
                $stats['present_days']++;
                $stats['late_minutes'] += $attendance->late_minutes;
                $stats['early_leave_minutes'] += $attendance->early_leave_minutes;
                $stats['overtime_minutes'] += $attendance->overtime_minutes;

                if ($attendance->worked_minutes === null) {
                    $stats['incomplete_days']++;

                    continue;
                }

                $shortfall = max(0, $scheduledMinutes - $attendance->worked_minutes);
                $excused = min($shortfall, $excusedByDay->get($date, 0));

                $stats['shortfall_minutes'] += $shortfall;
                $stats['excused_minutes'] += $excused;
                $stats['unexcused_minutes'] += max(0, $shortfall - $excused - $grace);

                continue;
            }

            $leave = $leaves->first(fn (LeaveRequest $l) => $l->start_date->toDateString() <= $date && $l->end_date->toDateString() >= $date);

            if ($leave === null) {
                $stats['absent_days']++;
            } elseif ($leave->type === 'unpaid') {
                $stats['unpaid_leave_days']++;
            } else {
                $stats['paid_leave_days']++;
            }
        }

        $periodDays = $periodStart->daysInMonth;
        $windowDays = (int) $from->diffInDays($to) + 1;
        $factor = min(1, $windowDays / $periodDays);

        $basic = (float) $employee->basic_salary;
        $fixed = $basic + (float) $employee->allowances;
        $proratedBasic = round($basic * $factor, 2);
        $proratedFixed = round($fixed * $factor, 2);

        $daily = $fixed / config('hr.days_per_month');
        $hourly = $scheduledMinutes > 0 ? $daily / ($scheduledMinutes / 60) : 0.0;

        $overtimePay = $employee->overtime_eligible
            ? round($stats['overtime_minutes'] / 60 * $hourly * config('hr.overtime_multiplier'), 2)
            : 0.0;

        $absence = round($stats['absent_days'] * $daily, 2);
        $unpaidLeave = round($stats['unpaid_leave_days'] * $daily, 2);
        $shortfallDeduction = round($stats['unexcused_minutes'] / 60 * $hourly * config('hr.shortfall_multiplier'), 2);

        $gross = round($proratedFixed + $overtimePay, 2);
        $attendanceDeductions = round($absence + $unpaidLeave + $shortfallDeduction, 2);
        $earned = max(0.0, round($gross - $attendanceDeductions, 2));

        $insurance = min($earned, round($proratedBasic * (float) $employee->insurance_pct / 100, 2));
        $tax = round(max(0, $earned - $insurance) * (float) $employee->tax_pct / 100, 2);

        return [
            'employee_id' => $employee->id,
            'employee_code' => $employee->code,
            'employee_name' => $employee->name,
            'period_days' => $windowDays,
            ...$stats,
            'basic_salary' => $proratedBasic,
            'allowances' => round($proratedFixed - $proratedBasic, 2),
            'overtime_pay' => $overtimePay,
            'gross_earnings' => $gross,
            'absence_deduction' => $absence,
            'unpaid_leave_deduction' => $unpaidLeave,
            'shortfall_deduction' => $shortfallDeduction,
            'attendance_deductions' => $attendanceDeductions,
            'earned_pay' => $earned,
            'insurance' => $insurance,
            'tax' => $tax,
            'net_pay' => round($earned - $insurance - $tax, 2),
        ];
    }
}

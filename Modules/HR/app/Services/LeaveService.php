<?php

namespace Modules\HR\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Support\ScheduleResolver;
use Modules\HR\Support\WorkCalendar;

/**
 * Leave requests: a range of days off with a type. Only working days count —
 * weekends and company holidays inside the range are skipped — and annual
 * leave can't go past the employee's yearly entitlement. Approved paid leave
 * (annual, sick, other) is paid in payroll; unpaid leave costs a day's salary.
 */
class LeaveService
{
    public function __construct(
        private readonly ScheduleResolver $schedules,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{employee_id: string, type: string, start_date: string, end_date: string, reason?: ?string, created_by?: ?string}  $data
     */
    public function create(string $tenantId, array $data): LeaveRequest
    {
        $employee = Employee::query()->where('tenant_id', $tenantId)->findOrFail($data['employee_id']);

        return DB::transaction(function () use ($tenantId, $employee, $data) {
            $days = $this->workingDays($employee, Carbon::parse($data['start_date']), Carbon::parse($data['end_date']));

            if ($days === 0) {
                throw ValidationException::withMessages(['start_date' => 'The range has no working days — it is all weekend or holiday.']);
            }

            $this->assertNoOverlap($employee, $data['start_date'], $data['end_date']);

            if ($data['type'] === 'annual') {
                $balance = $this->balance($employee, Carbon::parse($data['start_date'])->year);

                if ($days > $balance['remaining']) {
                    throw ValidationException::withMessages(['end_date' => "That is {$days} day(s) of annual leave but only {$balance['remaining']} remain for the year."]);
                }
            }

            $leave = LeaveRequest::create($data + ['tenant_id' => $tenantId, 'days' => $days]);

            $this->record($leave, 'INSERT', ['type' => $leave->type, 'days' => (string) $days], $data['created_by'] ?? null);

            return $leave;
        });
    }

    /**
     * @param  array{type?: string, start_date?: string, end_date?: string, reason?: ?string}  $data
     */
    public function update(LeaveRequest $leave, array $data, User $actor): LeaveRequest
    {
        $this->assertPending($leave);

        return DB::transaction(function () use ($leave, $data, $actor) {
            $merged = array_merge($leave->only(['type', 'reason']) + ['start_date' => $leave->start_date->toDateString(), 'end_date' => $leave->end_date->toDateString()], $data);

            $employee = $leave->employee;
            $days = $this->workingDays($employee, Carbon::parse($merged['start_date']), Carbon::parse($merged['end_date']));

            if ($days === 0) {
                throw ValidationException::withMessages(['start_date' => 'The range has no working days — it is all weekend or holiday.']);
            }

            $this->assertNoOverlap($employee, $merged['start_date'], $merged['end_date'], $leave->id);

            if ($merged['type'] === 'annual') {
                $balance = $this->balance($employee, Carbon::parse($merged['start_date'])->year, $leave->id);

                if ($days > $balance['remaining']) {
                    throw ValidationException::withMessages(['end_date' => "That is {$days} day(s) of annual leave but only {$balance['remaining']} remain for the year."]);
                }
            }

            $leave->update($data + ['days' => $days]);
            $this->record($leave, 'UPDATE', array_map('strval', $data), $actor->id);

            return $leave;
        });
    }

    public function decide(LeaveRequest $leave, string $decision, User $actor, ?string $reason = null): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $decision, $actor, $reason) {
            $locked = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            $locked->update([
                'status' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'rejection_reason' => $decision === 'rejected' ? $reason : null,
            ]);

            $this->record($locked, $decision === 'approved' ? 'APPROVE' : 'UPDATE', ['status' => $decision], $actor->id);

            return $locked;
        });
    }

    /**
     * Working days in the range for this employee: not their weekend, not a company holiday.
     */
    public function workingDays(Employee $employee, Carbon $from, Carbon $to): int
    {
        $schedule = $this->schedules->forEmployee($employee);
        $calendar = WorkCalendar::between($employee->tenant_id, $from, $to);
        $days = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (! $schedule->isWeekend($day) && ! $calendar->isHoliday($day)) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Annual leave for a calendar year: the entitlement, what has been approved, what is waiting.
     *
     * @return array{entitlement: int, taken: int, pending: int, remaining: int}
     */
    public function balance(Employee $employee, int $year, ?string $ignoreLeaveId = null): array
    {
        $annual = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'annual')
            ->whereYear('start_date', $year)
            ->when($ignoreLeaveId, fn ($query) => $query->whereKeyNot($ignoreLeaveId));

        $taken = (int) (clone $annual)->where('status', 'approved')->sum('days');
        $pending = (int) (clone $annual)->where('status', 'pending')->sum('days');

        return [
            'entitlement' => $employee->annual_leave_days,
            'taken' => $taken,
            'pending' => $pending,
            'remaining' => max(0, $employee->annual_leave_days - $taken - $pending),
        ];
    }

    private function assertPending(LeaveRequest $leave): void
    {
        if (! $leave->isPending()) {
            throw new HrRuleException('Only a pending leave request can be changed.');
        }
    }

    private function assertNoOverlap(Employee $employee, string $start, string $end, ?string $ignoreId = null): void
    {
        $overlaps = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['start_date' => "{$employee->name} already has leave requested or approved in that range."]);
        }
    }

    /**
     * @param  array<string, mixed>  $new
     */
    private function record(LeaveRequest $leave, string $operation, array $new, ?string $userId): void
    {
        $this->auditLog->record([
            'module' => 'hr',
            'entity_type' => 'LeaveRequest',
            'entity_id' => $leave->id,
            'operation' => $operation,
            'user_id' => $userId,
            'tenant_id' => $leave->tenant_id,
            'new_values' => $new,
        ]);
    }
}

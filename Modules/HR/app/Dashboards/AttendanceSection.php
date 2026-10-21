<?php

namespace Modules\HR\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\HR\Models\Attendance;
use Modules\HR\Models\Employee;

/**
 * Attendance: who is in today, and how punctual and productive the period was.
 */
class AttendanceSection implements DashboardSection
{
    public function key(): string
    {
        return 'attendance';
    }

    public function label(): string
    {
        return 'Attendance';
    }

    public function permission(): ?string
    {
        return 'attendances.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $records = fn (DashboardPeriod $in) => Attendance::query()->where('hr_attendances.tenant_id', $user->tenant_id)
            ->whereBetween('work_date', [$in->from->toDateString(), $in->to->toDateString()]);
        $today = Attendance::query()->where('hr_attendances.tenant_id', $user->tenant_id)->whereDate('work_date', today());

        $totals = fn (DashboardPeriod $in) => $records($in)->selectRaw('coalesce(sum(worked_minutes), 0) as worked, coalesce(sum(late_minutes), 0) as late, coalesce(sum(overtime_minutes), 0) as overtime, sum(late_minutes > 0) as late_days, count(*) as days')->first();
        $now = $totals($period);
        $before = $totals($period->previous());

        $lateByDay = $records($period)->selectRaw('work_date as day, sum(late_minutes > 0) as total')->groupBy('work_date')->pluck('total', 'day')->map(fn ($v) => (int) $v);
        $presentByDay = $records($period)->selectRaw('work_date as day, count(*) as total')->groupBy('work_date')->pluck('total', 'day')->map(fn ($v) => (int) $v);

        return [
            'kpis' => [
                Widgets::kpi('present_today', 'Present today', (clone $today)->whereNotNull('check_in')->count()),
                Widgets::kpi('not_in_yet', 'Active employees not in yet', max(0, Employee::query()->where('tenant_id', $user->tenant_id)->where('status', 'active')->count() - (clone $today)->whereNotNull('check_in')->count())),
                Widgets::kpi('late_arrivals', 'Late arrivals', (int) $now->late_days, (int) $before->late_days),
                Widgets::kpi('hours_worked', 'Hours worked', round((int) $now->worked / 60, 1), round((int) $before->worked / 60, 1), 'hours'),
                Widgets::kpi('overtime_hours', 'Overtime hours', round((int) $now->overtime / 60, 1), round((int) $before->overtime / 60, 1), 'hours'),
            ],
            'charts' => [
                Widgets::chart('attendance_by_day', 'People in per day', 'bar', Widgets::daily($period, $presentByDay)),
                Widgets::chart('late_by_day', 'Late arrivals per day', 'bar', Widgets::daily($period, $lateByDay)),
            ],
        ];
    }
}

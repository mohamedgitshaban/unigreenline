<?php

namespace Modules\HR\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\HR\Models\LeaveRequest;

/**
 * Leave: what is waiting for a decision, who is away today, and how much leave was taken.
 */
class LeaveSection implements DashboardSection
{
    public function key(): string
    {
        return 'leave';
    }

    public function label(): string
    {
        return 'Leave';
    }

    public function permission(): ?string
    {
        return 'leave-requests.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $requests = fn () => LeaveRequest::query()->where('hr_leave_requests.tenant_id', $user->tenant_id);
        $approvedIn = fn (DashboardPeriod $in) => $requests()->where('status', 'approved')->whereDate('start_date', '<=', $in->to->toDateString())->whereDate('end_date', '>=', $in->from->toDateString());

        $days = (float) $approvedIn($period)->sum('days');
        $daysBefore = (float) $approvedIn($period->previous())->sum('days');
        $byType = $approvedIn($period)->selectRaw('type, sum(days) as total')->groupBy('type')->get();

        return [
            'kpis' => [
                Widgets::kpi('leave_pending', 'Requests waiting for a decision', $requests()->where('status', 'pending')->count()),
                Widgets::kpi('on_leave_today', 'Away today', $requests()->where('status', 'approved')->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->count()),
                Widgets::kpi('leave_days', 'Leave days taken', $days, $daysBefore, 'days'),
            ],
            'charts' => [
                Widgets::chart('leave_by_type', 'Leave days by type', 'donut', $byType->map(fn ($row) => ['label' => $row->type, 'value' => (float) $row->total])->all(), 'days'),
            ],
        ];
    }
}

<?php

namespace Modules\Recap\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Recap\Models\Recap;

/**
 * Stock write-offs (recaps): what was written off and why, and what is waiting to be approved.
 */
class WriteOffsSection implements DashboardSection
{
    public function key(): string
    {
        return 'write-offs';
    }

    public function label(): string
    {
        return 'Write-offs';
    }

    public function permission(): ?string
    {
        return 'recaps.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $approved = fn (DashboardPeriod $in) => Recap::query()->where('recaps.tenant_id', $user->tenant_id)->where('status', 'approved')
            ->whereBetween('recap_date', [$in->from->toDateString(), $in->to->toDateString()]);

        $sums = fn (DashboardPeriod $in) => $approved($in)->selectRaw('count(*) as total, coalesce(sum(total_value), 0) as value')->first();
        $now = $sums($period);
        $before = $sums($period->previous());
        $byReason = $approved($period)->selectRaw('reason, coalesce(sum(total_value), 0) as value')->groupBy('reason')->get();

        return [
            'kpis' => [
                Widgets::kpi('writeoffs', 'Write-offs approved', (int) $now->total, (int) $before->total),
                Widgets::kpi('writeoff_value', 'Value written off', (float) $now->value, (float) $before->value, 'currency'),
                Widgets::kpi('writeoffs_pending', 'Waiting for approval', Recap::query()->where('tenant_id', $user->tenant_id)->where('status', 'draft')->count()),
            ],
            'charts' => [
                Widgets::chart('writeoffs_by_reason', 'Value by reason', 'donut', $byReason->map(fn ($row) => ['label' => $row->reason, 'value' => (float) $row->value])->all(), 'currency'),
            ],
        ];
    }
}

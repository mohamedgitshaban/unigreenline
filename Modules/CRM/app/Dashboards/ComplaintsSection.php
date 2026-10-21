<?php

namespace Modules\CRM\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\CRM\Models\Complaint;

/**
 * Customer complaints: how many are open, how urgent, and how fast they are resolved.
 */
class ComplaintsSection implements DashboardSection
{
    public function key(): string
    {
        return 'complaints';
    }

    public function label(): string
    {
        return 'Complaints';
    }

    public function permission(): ?string
    {
        return 'complaints.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $complaints = fn () => Complaint::query()->where('complaints.tenant_id', $user->tenant_id);
        $raised = fn (DashboardPeriod $in) => $complaints()->whereBetween('complaint_date', [$in->from->toDateString(), $in->to->toDateString()])->count();

        $open = fn () => $complaints()->where('status', 'investigating');
        $byPriority = $open()->selectRaw('priority, count(*) as total')->groupBy('priority')->pluck('total', 'priority');
        $avgDays = $complaints()->whereNotNull('resolved_date')->whereBetween('resolved_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw('avg(datediff(resolved_date, complaint_date)) as days')->value('days');

        return [
            'kpis' => [
                Widgets::kpi('complaints', 'Complaints raised', $raised($period), $raised($period->previous())),
                Widgets::kpi('open_complaints', 'Open complaints', $open()->count()),
                Widgets::kpi('high_priority', 'Open and high priority', $open()->where('priority', 'high')->count()),
                Widgets::kpi('resolution_days', 'Average days to resolve', round((float) $avgDays, 1), null, 'days'),
            ],
            'charts' => [
                Widgets::chart('open_by_priority', 'Open complaints by priority', 'donut', collect(['high', 'medium', 'low'])->map(fn ($p) => ['label' => $p, 'value' => (int) ($byPriority[$p] ?? 0)])->all()),
            ],
        ];
    }
}

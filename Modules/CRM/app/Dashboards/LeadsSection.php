<?php

namespace Modules\CRM\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\CRM\Models\Lead;

/**
 * The lead pipeline: how many leads are in each stage, what they are worth and how many were won.
 */
class LeadsSection implements DashboardSection
{
    public function key(): string
    {
        return 'leads';
    }

    public function label(): string
    {
        return 'Leads';
    }

    public function permission(): ?string
    {
        return 'leads.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $leads = fn () => Lead::query()->where('leads.tenant_id', $user->tenant_id);
        $created = fn (DashboardPeriod $in) => $leads()->whereBetween('leads.created_at', [$in->from, $in->to])->count();

        $byStatus = $leads()->selectRaw('status, count(*) as total, coalesce(sum(value), 0) as value')->groupBy('status')->get()->keyBy('status');
        $open = $leads()->whereNotIn('status', ['won', 'lost']);
        $won = (clone $leads())->where('status', 'won')->whereBetween('leads.updated_at', [$period->from, $period->to])->count();
        $lost = (clone $leads())->where('status', 'lost')->whereBetween('leads.updated_at', [$period->from, $period->to])->count();

        return [
            'kpis' => [
                Widgets::kpi('new_leads', 'New leads', $created($period), $created($period->previous())),
                Widgets::kpi('open_leads', 'Open leads', (clone $open)->count()),
                Widgets::kpi('pipeline_value', 'Open pipeline value', (float) (clone $open)->sum('value'), null, 'currency'),
                Widgets::kpi('win_rate', 'Won of those closed', $won + $lost > 0 ? round($won / ($won + $lost) * 100, 1) : 0.0, null, 'percent'),
            ],
            'charts' => [
                Widgets::chart('leads_by_status', 'Leads by stage', 'bar', collect(['new', 'qualified', 'proposal', 'won', 'lost'])->map(fn ($status) => ['label' => $status, 'value' => (int) ($byStatus[$status]->total ?? 0)])->all()),
                Widgets::chart('pipeline_by_status', 'Value by stage', 'bar', collect(['new', 'qualified', 'proposal', 'won'])->map(fn ($status) => ['label' => $status, 'value' => (float) ($byStatus[$status]->value ?? 0)])->all(), 'currency'),
            ],
        ];
    }
}

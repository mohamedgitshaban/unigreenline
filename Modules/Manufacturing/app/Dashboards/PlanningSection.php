<?php

namespace Modules\Manufacturing\Dashboards;

use Illuminate\Support\Facades\DB;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;

/**
 * Planning and quality: what the latest planning run proposes, and the quality checks that failed.
 */
class PlanningSection implements DashboardSection
{
    public function key(): string
    {
        return 'planning';
    }

    public function label(): string
    {
        return 'Planning and quality';
    }

    public function permission(): ?string
    {
        return 'mrp-runs.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $suggestions = fn () => DB::table('mrp_suggestions')->where('tenant_id', $user->tenant_id)->where('status', 'proposed');
        $byKind = $suggestions()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');

        return [
            'kpis' => [
                Widgets::kpi('mrp_suggestions_open', 'Open planning suggestions', $suggestions()->count()),
                Widgets::kpi('mrp_to_make', 'Suggested to make', (int) ($byKind['manufacture'] ?? 0)),
                Widgets::kpi('mrp_to_buy', 'Suggested to buy', (int) ($byKind['purchase'] ?? 0)),
                Widgets::kpi('quality_alerts_open', 'Open quality alerts', DB::table('quality_alerts')->where('tenant_id', $user->tenant_id)->where('status', 'open')->count()),
            ],
        ];
    }
}

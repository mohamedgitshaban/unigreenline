<?php

namespace Modules\Accounting\Dashboards;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\FixedAsset;
use Modules\Accounting\Models\FixedAssetDepreciation;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;

/**
 * Fixed assets: what the company owns, what it is worth after depreciation and what depreciation is still to come.
 */
class AssetsSection implements DashboardSection
{
    public function key(): string
    {
        return 'assets';
    }

    public function label(): string
    {
        return 'Fixed assets';
    }

    public function permission(): ?string
    {
        return 'fixed-assets.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $live = fn () => FixedAsset::query()->where('fixed_assets.tenant_id', $user->tenant_id)->whereIn('fixed_assets.status', FixedAsset::LIVE);
        $posted = fn (DashboardPeriod $in) => FixedAssetDepreciation::query()->where('tenant_id', $user->tenant_id)->where('status', 'posted')
            ->whereBetween('depreciation_date', [$in->from->toDateString(), $in->to->toDateString()])->sum('amount');

        $totals = $live()->selectRaw('count(*) as assets, coalesce(sum(original_value), 0) as cost, coalesce(sum(accumulated_depreciation), 0) as accumulated')->first();
        $byCategory = $live()->join('asset_categories', 'asset_categories.id', '=', 'fixed_assets.category_id')
            ->selectRaw('asset_categories.name, sum(original_value - accumulated_depreciation) as book')->groupBy('asset_categories.name')->orderByDesc('book')->limit(8)->get();

        $from = now()->startOfMonth();
        $forecast = FixedAssetDepreciation::query()->where('tenant_id', $user->tenant_id)->where('status', 'planned')
            ->whereBetween('depreciation_date', [$from->toDateString(), $from->copy()->addMonths(6)->subDay()->toDateString()])
            ->select(DB::raw("date_format(depreciation_date, '%Y-%m') as month"), DB::raw('sum(amount) as amount'))->groupBy('month')->pluck('amount', 'month');

        $points = [];

        for ($i = 0; $i < 6; $i++) {
            $month = $from->copy()->addMonths($i)->format('Y-m');
            $points[] = ['label' => $month, 'value' => (float) ($forecast[$month] ?? 0)];
        }

        return [
            'kpis' => [
                Widgets::kpi('assets_on_books', 'Assets on the books', (int) $totals->assets),
                Widgets::kpi('assets_cost', 'Cost', (float) $totals->cost, null, 'currency'),
                Widgets::kpi('assets_book_value', 'Book value', (float) $totals->cost - (float) $totals->accumulated, null, 'currency'),
                Widgets::kpi('depreciation_period', 'Depreciation booked', (float) $posted($period), (float) $posted($period->previous()), 'currency'),
                Widgets::kpi('depreciation_due', 'Depreciation due and not yet booked', FixedAssetDepreciation::query()->where('tenant_id', $user->tenant_id)->where('status', 'planned')->whereDate('depreciation_date', '<=', today())->whereHas('asset', fn ($q) => $q->where('status', 'running'))->count()),
            ],
            'charts' => [
                Widgets::chart('book_value_by_category', 'Book value by category', 'donut', $byCategory->map(fn ($row) => ['label' => $row->name, 'value' => (float) $row->book])->all(), 'currency'),
                Widgets::chart('depreciation_forecast', 'Depreciation still to come (6 months)', 'bar', $points, 'currency'),
            ],
        ];
    }
}

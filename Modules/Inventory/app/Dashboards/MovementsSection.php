<?php

namespace Modules\Inventory\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Support\WarehouseAccess;

/**
 * Stock in and out of the warehouses the user may see, from the stock ledger.
 */
class MovementsSection implements DashboardSection
{
    public function __construct(private readonly WarehouseAccess $access) {}

    public function key(): string
    {
        return 'movements';
    }

    public function label(): string
    {
        return 'Stock movements';
    }

    public function permission(): ?string
    {
        return 'warehouses.movements';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $visible = $this->access->idsFor($user);
        $movements = fn (DashboardPeriod $in) => StockMovement::query()->where('stock_movements.tenant_id', $user->tenant_id)->where('stock_movements.type', '!=', 'opening_balance')
            ->when($visible !== null, fn ($q) => $q->whereIn('stock_movements.warehouse_id', $visible))
            ->whereBetween('occurred_at', [$in->from, $in->to]);

        $sums = fn (DashboardPeriod $in) => $movements($in)->selectRaw('coalesce(sum(case when qty_cartons > 0 then qty_cartons end), 0) as received, coalesce(sum(case when qty_cartons < 0 then -qty_cartons end), 0) as issued')->first();
        $now = $sums($period);
        $before = $sums($period->previous());

        $received = $movements($period)->where('qty_cartons', '>', 0)->selectRaw('date(occurred_at) as day, sum(qty_cartons) as total')->groupBy('day')->pluck('total', 'day')->map(fn ($v) => (int) $v);
        $issued = $movements($period)->where('qty_cartons', '<', 0)->selectRaw('date(occurred_at) as day, sum(-qty_cartons) as total')->groupBy('day')->pluck('total', 'day')->map(fn ($v) => (int) $v);
        $byType = $movements($period)->selectRaw('type, sum(abs(qty_cartons)) as total')->groupBy('type')->get();

        $topIssued = $movements($period)->where('qty_cartons', '<', 0)->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->selectRaw('products.id, products.name, sum(-qty_cartons) as cartons')->groupBy('products.id', 'products.name')->orderByDesc('cartons')->limit(5)->get();

        return [
            'kpis' => [
                Widgets::kpi('received_cartons', 'Cartons received', (int) $now->received, (int) $before->received, 'cartons'),
                Widgets::kpi('issued_cartons', 'Cartons issued', (int) $now->issued, (int) $before->issued, 'cartons'),
                Widgets::kpi('net_cartons', 'Net change', (int) $now->received - (int) $now->issued, (int) $before->received - (int) $before->issued, 'cartons'),
            ],
            'charts' => [
                Widgets::chart('received_by_day', 'Cartons received per day', 'bar', Widgets::daily($period, $received), 'cartons'),
                Widgets::chart('issued_by_day', 'Cartons issued per day', 'bar', Widgets::daily($period, $issued), 'cartons'),
                Widgets::chart('movements_by_type', 'Cartons moved by reason', 'donut', $byType->map(fn ($row) => ['label' => StockMovement::TYPES[$row->type] ?? $row->type, 'value' => (int) $row->total])->all(), 'cartons'),
            ],
            'tables' => [
                Widgets::table('top_issued_products', 'Most issued products', [['key' => 'name', 'label' => 'Product'], ['key' => 'cartons', 'label' => 'Cartons']],
                    $topIssued->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'cartons' => (int) $row->cartons])->all()),
            ],
        ];
    }
}

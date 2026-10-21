<?php

namespace Modules\Manufacturing\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Inventory\Support\WarehouseAccess;
use Modules\Manufacturing\Models\ProductionOrder;

/**
 * Production: what is being made, how much was finished, what is late, for the warehouses the user may see.
 */
class ProductionSection implements DashboardSection
{
    public function __construct(private readonly WarehouseAccess $access) {}

    public function key(): string
    {
        return 'production';
    }

    public function label(): string
    {
        return 'Production';
    }

    public function permission(): ?string
    {
        return 'production-orders.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $visible = $this->access->idsFor($user);
        $orders = fn () => ProductionOrder::query()->where('production_orders.tenant_id', $user->tenant_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('production_orders.warehouse_id', $visible));
        $finishedIn = fn (DashboardPeriod $in) => $orders()->where('status', 'completed')->whereBetween('completed_at', [$in->from, $in->to]);

        $sums = fn (DashboardPeriod $in) => $finishedIn($in)->selectRaw('count(*) as orders, coalesce(sum(produced_qty_cartons), 0) as produced, coalesce(sum(rejected_qty_cartons), 0) as rejected')->first();
        $now = $sums($period);
        $before = $sums($period->previous());

        $open = fn () => $orders()->whereIn('production_orders.status', ['draft', 'confirmed', 'planned', 'in_progress', 'paused']);
        $byStatus = $orders()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $producedByDay = $finishedIn($period)->selectRaw('date(completed_at) as day, sum(produced_qty_cartons) as total')->groupBy('day')->pluck('total', 'day')->map(fn ($v) => (int) $v);
        $madeAndRejected = (int) $now->produced + (int) $now->rejected;

        $late = $open()->whereNotNull('production_orders.planned_finish')->where('production_orders.planned_finish', '<', now())
            ->join('boms', 'boms.id', '=', 'production_orders.bom_id')->join('products', 'products.id', '=', 'boms.product_id')
            ->orderBy('production_orders.planned_finish')->limit(5)->get(['production_orders.id', 'production_orders.number', 'products.name as product', 'production_orders.planned_finish', 'production_orders.status']);

        return [
            'kpis' => [
                Widgets::kpi('orders_finished', 'Orders finished', (int) $now->orders, (int) $before->orders),
                Widgets::kpi('cartons_produced', 'Cartons produced', (int) $now->produced, (int) $before->produced, 'cartons'),
                Widgets::kpi('yield', 'Yield (good of good + rejected)', $madeAndRejected > 0 ? round((int) $now->produced / $madeAndRejected * 100, 1) : 0.0, null, 'percent'),
                Widgets::kpi('orders_in_progress', 'Orders in progress', $open()->whereIn('production_orders.status', ['in_progress', 'paused'])->count()),
                Widgets::kpi('orders_late', 'Orders past their planned finish', $open()->whereNotNull('production_orders.planned_finish')->where('production_orders.planned_finish', '<', now())->count()),
            ],
            'charts' => [
                Widgets::chart('production_by_day', 'Cartons produced per day', 'bar', Widgets::daily($period, $producedByDay), 'cartons'),
                Widgets::chart('orders_by_status', 'Orders by status', 'donut', $byStatus->map(fn ($total, $status) => ['label' => $status, 'value' => (int) $total])->values()->all()),
            ],
            'tables' => [
                Widgets::table('late_orders', 'Latest to finish', [['key' => 'number', 'label' => 'Order'], ['key' => 'product', 'label' => 'Product'], ['key' => 'planned_finish', 'label' => 'Planned finish'], ['key' => 'status', 'label' => 'Status']],
                    $late->map(fn ($row) => ['id' => $row->id, 'number' => $row->number, 'product' => $row->product, 'planned_finish' => $row->planned_finish, 'status' => $row->status])->all()),
            ],
        ];
    }
}

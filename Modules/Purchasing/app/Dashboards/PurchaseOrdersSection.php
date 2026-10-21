<?php

namespace Modules\Purchasing\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Purchasing\Models\PurchaseOrder;

/**
 * Purchase orders: how much is being bought, from whom, and what is waiting for pricing, for goods or is late.
 */
class PurchaseOrdersSection implements DashboardSection
{
    public function key(): string
    {
        return 'purchase-orders';
    }

    public function label(): string
    {
        return 'Purchase orders';
    }

    public function permission(): ?string
    {
        return 'purchase-orders.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $orders = fn (DashboardPeriod $in) => PurchaseOrder::query()->where('purchase_orders.tenant_id', $user->tenant_id)->where('purchase_orders.status', '!=', 'cancelled')
            ->whereBetween('purchase_orders.order_date', [$in->from->toDateString(), $in->to->toDateString()]);
        $open = fn () => PurchaseOrder::query()->where('purchase_orders.tenant_id', $user->tenant_id)->whereIn('purchase_orders.status', ['draft', 'sent', 'pending', 'pending_pricing']);

        $sums = fn (DashboardPeriod $in) => $orders($in)->selectRaw('count(*) as orders, coalesce(sum(total), 0) as spend')->first();
        $now = $sums($period);
        $before = $sums($period->previous());

        $spendByDay = $orders($period)->selectRaw('order_date as day, sum(total) as spend')->groupBy('order_date')->pluck('spend', 'day')->map(fn ($v) => (float) $v);
        $byStatus = $orders($period)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $bySupplier = $orders($period)->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->selectRaw('suppliers.id, suppliers.name, count(*) as orders, sum(purchase_orders.total) as spend')->groupBy('suppliers.id', 'suppliers.name')->orderByDesc('spend')->limit(5)->get();

        return [
            'kpis' => [
                Widgets::kpi('purchase_orders', 'Purchase orders', (int) $now->orders, (int) $before->orders),
                Widgets::kpi('purchase_spend', 'Purchases', (float) $now->spend, (float) $before->spend, 'currency'),
                Widgets::kpi('pending_pricing', 'Waiting for pricing', $open()->where('status', 'pending_pricing')->count()),
                Widgets::kpi('awaiting_goods', 'Waiting for goods', $open()->whereIn('status', ['sent', 'pending'])->count()),
                Widgets::kpi('late_purchase_orders', 'Past their expected date', $open()->whereDate('expected_date', '<', today())->count()),
            ],
            'charts' => [
                Widgets::chart('spend_by_day', 'Purchases per day', 'line', Widgets::daily($period, $spendByDay), 'currency'),
                Widgets::chart('purchase_orders_by_status', 'Purchase orders by status', 'donut', $byStatus->map(fn ($total, $status) => ['label' => $status, 'value' => (int) $total])->values()->all()),
            ],
            'tables' => [
                Widgets::table('top_suppliers', 'Top suppliers', [['key' => 'name', 'label' => 'Supplier'], ['key' => 'orders', 'label' => 'Orders'], ['key' => 'spend', 'label' => 'Purchases']],
                    $bySupplier->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'orders' => (int) $row->orders, 'spend' => round((float) $row->spend, 2)])->all()),
            ],
        ];
    }
}

<?php

namespace Modules\Sales\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\Sales\Models\SalesOrder;

/**
 * Sales orders: how many, how much, what state they are in, who sells and who buys.
 * A user whose scope is `team` sees their own and their team's orders only.
 */
class OrdersSection implements DashboardSection
{
    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'orders';
    }

    public function label(): string
    {
        return 'Sales orders';
    }

    public function permission(): ?string
    {
        return 'sales-orders.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $orders = fn (DashboardPeriod $in) => SalesOrder::query()
            ->where('sales_orders.tenant_id', $user->tenant_id)
            ->visibleTo($user)
            ->where('sales_orders.status', '!=', 'cancelled')
            ->whereBetween('sales_orders.order_date', [$in->from->toDateString(), $in->to->toDateString()]);

        $previous = $period->previous();
        $now = (clone $orders($period))->selectRaw('count(*) as orders, coalesce(sum(total), 0) as value')->first();
        $before = (clone $orders($previous))->selectRaw('count(*) as orders, coalesce(sum(total), 0) as value')->first();

        $openOrders = SalesOrder::query()->where('sales_orders.tenant_id', $user->tenant_id)->visibleTo($user)->whereIn('status', ['draft', 'picking'])->count();
        $delivered = (clone $orders($period))->where('sales_orders.status', 'delivered')->count();

        $byDay = $orders($period)->selectRaw('order_date as day, sum(total) as value')->groupBy('order_date')->pluck('value', 'day')->map(fn ($v) => (float) $v);
        $byStatus = $orders($period)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $topCustomers = $orders($period)
            ->join('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->selectRaw('customers.id, customers.name, count(*) as orders, sum(sales_orders.total) as value')
            ->groupBy('customers.id', 'customers.name')->orderByDesc('value')->limit(5)->get();

        $tables = [Widgets::table('top_customers', 'Top customers', [['key' => 'name', 'label' => 'Customer'], ['key' => 'orders', 'label' => 'Orders'], ['key' => 'value', 'label' => 'Sales']],
            $topCustomers->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'orders' => (int) $row->orders, 'value' => round((float) $row->value, 2)])->all())];

        $reps = $orders($period)
            ->join('users', 'users.id', '=', 'sales_orders.sales_rep_id')
            ->selectRaw('users.id, users.name, count(*) as orders, sum(sales_orders.total) as value')
            ->groupBy('users.id', 'users.name')->orderByDesc('value')->limit(10)->get();

        if ($reps->count() > 1 || ! $this->scope->isTeamScoped($user)) {
            $tables[] = Widgets::table('by_sales_rep', 'Sales by rep', [['key' => 'name', 'label' => 'Rep'], ['key' => 'orders', 'label' => 'Orders'], ['key' => 'value', 'label' => 'Sales']],
                $reps->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'orders' => (int) $row->orders, 'value' => round((float) $row->value, 2)])->all());
        }

        return [
            'kpis' => [
                Widgets::kpi('orders', 'Orders', (int) $now->orders, (int) $before->orders),
                Widgets::kpi('sales', 'Sales value', (float) $now->value, (float) $before->value, 'currency'),
                Widgets::kpi('average_order', 'Average order', $now->orders > 0 ? (float) $now->value / $now->orders : 0.0, $before->orders > 0 ? (float) $before->value / $before->orders : 0.0, 'currency'),
                Widgets::kpi('open_orders', 'Open orders (draft or picking)', $openOrders),
                Widgets::kpi('delivered_orders', 'Delivered orders', $delivered),
            ],
            'charts' => [
                Widgets::chart('sales_by_day', 'Sales per day', 'line', Widgets::daily($period, $byDay), 'currency'),
                Widgets::chart('orders_by_status', 'Orders by status', 'donut', $byStatus->map(fn ($total, $status) => ['label' => $status, 'value' => (int) $total])->values()->all()),
            ],
            'tables' => $tables,
        ];
    }
}

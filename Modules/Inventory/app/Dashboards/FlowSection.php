<?php

namespace Modules\Inventory\Dashboards;

use Illuminate\Support\Facades\DB;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Inventory\Support\WarehouseAccess;

/**
 * What is about to arrive (purchase orders) and what is about to leave (sales orders) for the warehouses the user may see.
 */
class FlowSection implements DashboardSection
{
    public function __construct(private readonly WarehouseAccess $access) {}

    public function key(): string
    {
        return 'flow';
    }

    public function label(): string
    {
        return 'Incoming and outgoing';
    }

    public function permission(): ?string
    {
        return 'warehouses.incoming';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $visible = $this->access->idsFor($user);

        $incoming = fn () => DB::table('purchase_orders')->where('purchase_orders.tenant_id', $user->tenant_id)->whereIn('purchase_orders.status', ['draft', 'sent', 'pending', 'pending_pricing'])
            ->when($visible !== null, fn ($q) => $q->whereIn('purchase_orders.warehouse_id', $visible));
        $outgoing = fn () => DB::table('sales_orders')->where('sales_orders.tenant_id', $user->tenant_id)->whereIn('sales_orders.status', ['draft', 'picking', 'invoiced'])
            ->when($visible !== null, fn ($q) => $q->where(fn ($inner) => $inner->whereIn('sales_orders.warehouse_id', $visible)->orWhereExists(
                fn ($lines) => $lines->select(DB::raw(1))->from('sales_order_lines')->whereColumn('sales_order_lines.so_id', 'sales_orders.id')->whereIn('sales_order_lines.warehouse_id', $visible)
            )));

        $expected = (int) DB::table('purchase_order_lines')->whereIn('po_id', $incoming()->select('purchase_orders.id'))->sum('qty_cartons');
        $next = $incoming()->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')->join('warehouses', 'warehouses.id', '=', 'purchase_orders.warehouse_id')
            ->orderByRaw('purchase_orders.expected_date is null, purchase_orders.expected_date')->limit(5)
            ->get(['purchase_orders.id', 'suppliers.name as supplier', 'warehouses.name as warehouse', 'purchase_orders.expected_date', 'purchase_orders.status']);
        $due = $outgoing()->join('customers', 'customers.id', '=', 'sales_orders.customer_id')->orderBy('sales_orders.order_date')->limit(5)
            ->get(['sales_orders.id', 'customers.name as customer', 'sales_orders.order_date', 'sales_orders.status']);

        return [
            'kpis' => [
                Widgets::kpi('open_purchase_orders', 'Purchase orders on their way', $incoming()->count()),
                Widgets::kpi('cartons_expected', 'Cartons expected', $expected, null, 'cartons'),
                Widgets::kpi('late_purchase_orders', 'Purchase orders past their expected date', $incoming()->whereDate('purchase_orders.expected_date', '<', today())->count()),
                Widgets::kpi('orders_to_prepare', 'Sales orders to prepare', $outgoing()->whereIn('sales_orders.status', ['draft', 'picking'])->count()),
                Widgets::kpi('orders_to_deliver', 'Sales orders to hand over', $outgoing()->where('sales_orders.status', 'invoiced')->count()),
            ],
            'tables' => [
                Widgets::table('next_arrivals', 'Next arrivals', [['key' => 'supplier', 'label' => 'Supplier'], ['key' => 'warehouse', 'label' => 'Warehouse'], ['key' => 'expected_date', 'label' => 'Expected'], ['key' => 'status', 'label' => 'Status']],
                    $next->map(fn ($row) => ['id' => $row->id, 'supplier' => $row->supplier, 'warehouse' => $row->warehouse, 'expected_date' => $row->expected_date, 'status' => $row->status])->all()),
                Widgets::table('orders_due_out', 'Orders waiting to leave', [['key' => 'customer', 'label' => 'Customer'], ['key' => 'order_date', 'label' => 'Ordered'], ['key' => 'status', 'label' => 'Status']],
                    $due->map(fn ($row) => ['id' => $row->id, 'customer' => $row->customer, 'order_date' => $row->order_date, 'status' => $row->status])->all()),
            ],
        ];
    }
}

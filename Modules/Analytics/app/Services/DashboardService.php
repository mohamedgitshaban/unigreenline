<?php

namespace Modules\Analytics\Services;

use Modules\Core\Dashboards\Widgets;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;

/**
 * Dashboard KPI cards (spec §8): monthly sales, active customers, collected
 * amount, outstanding AR, overdue amount, inventory value, critical expiry
 * count, pending deliveries.
 */
class DashboardService
{
    /** Days-until-expiry threshold for "critical" — not specified by the spec, chosen as a reasonable default. */
    public const CRITICAL_EXPIRY_DAYS = 30;

    public function generate(string $tenantId): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        // Cancelled orders are not sales, the same as on the sales dashboard.
        $monthlySales = SalesOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('order_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('total');

        $activeCustomers = Customer::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $collectedAmount = Collection::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('payment_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('amount');

        $outstandingAr = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['outstanding', 'partial', 'overdue'])
            ->sum('balance');

        $overdueAmount = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'overdue')
            ->sum('balance');

        $inventoryValue = Warehouse::query()
            ->where('tenant_id', $tenantId)
            ->sum('stock_value');

        $criticalExpiryCount = InventoryBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('qty_cartons', '>', 0)
            ->whereDate('exp_date', '<=', now()->addDays(self::CRITICAL_EXPIRY_DAYS))
            ->count();

        $pendingDeliveries = Delivery::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'preparing', 'out_for_delivery'])
            ->count();

        return [
            'monthly_sales' => $this->money($monthlySales),
            'active_customers' => $activeCustomers,
            'collected_amount' => $this->money($collectedAmount),
            'outstanding_ar' => $this->money($outstandingAr),
            'overdue_amount' => $this->money($overdueAmount),
            'inventory_value' => $this->money($inventoryValue),
            'critical_expiry_count' => $criticalExpiryCount,
            'pending_deliveries' => $pendingDeliveries,
            // Where the data behind each number can be read, so a click on a card can open it.
            'links' => [
                'monthly_sales' => Widgets::link('sales-orders', ['filter[order_date][gte]' => $monthStart->toDateString(), 'filter[order_date][lte]' => $monthEnd->toDateString(), 'filter[status][ne]' => 'cancelled']),
                'active_customers' => Widgets::link('customers', ['filter[status]' => 'active']),
                'collected_amount' => Widgets::link('collections', ['filter[payment_date][gte]' => $monthStart->toDateString(), 'filter[payment_date][lte]' => $monthEnd->toDateString()]),
                'outstanding_ar' => Widgets::link('invoices', ['filter[status][in]' => 'outstanding,partial,overdue']),
                'overdue_amount' => Widgets::link('invoices', ['filter[status]' => 'overdue']),
                'inventory_value' => Widgets::link('analytics/stock-snapshot'),
                'critical_expiry_count' => Widgets::link('analytics/expiry'),
                'pending_deliveries' => Widgets::link('deliveries', ['filter[status][in]' => 'pending,preparing,out_for_delivery']),
            ],
        ];
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}

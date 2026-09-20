<?php

namespace Modules\Analytics\Services;

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

        $monthlySales = SalesOrder::query()
            ->where('tenant_id', $tenantId)
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
        ];
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}

<?php

namespace Modules\Analytics\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Analytics\Services\DashboardService;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_sales_only_sums_orders_within_the_current_month(): void
    {
        $tenant = Tenant::factory()->create();
        SalesOrder::factory()->recycle($tenant)->create(['order_date' => now()->startOfMonth()->addDays(2), 'total' => 100]);
        SalesOrder::factory()->recycle($tenant)->create(['order_date' => now()->subMonths(2), 'total' => 999]);

        $dashboard = $this->service()->generate($tenant->id);

        $this->assertSame('100.00', $dashboard['monthly_sales']);
    }

    public function test_active_customers_excludes_inactive_ones(): void
    {
        $tenant = Tenant::factory()->create();
        Customer::factory()->recycle($tenant)->create(['status' => 'active']);
        Customer::factory()->recycle($tenant)->create(['status' => 'inactive']);

        $dashboard = $this->service()->generate($tenant->id);

        $this->assertSame(1, $dashboard['active_customers']);
    }

    public function test_outstanding_ar_sums_outstanding_partial_and_overdue_invoice_balances(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        Invoice::factory()->recycle([$tenant, $customer])->create(['status' => 'outstanding', 'balance' => 100]);
        Invoice::factory()->recycle([$tenant, $customer])->create(['status' => 'partial', 'balance' => 50]);
        Invoice::factory()->recycle([$tenant, $customer])->create(['status' => 'paid', 'balance' => 0]);

        $dashboard = $this->service()->generate($tenant->id);

        $this->assertSame('150.00', $dashboard['outstanding_ar']);
    }

    public function test_overdue_amount_only_sums_overdue_invoices(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        Invoice::factory()->recycle([$tenant, $customer])->create(['status' => 'overdue', 'balance' => 75]);
        Invoice::factory()->recycle([$tenant, $customer])->create(['status' => 'outstanding', 'balance' => 200]);

        $dashboard = $this->service()->generate($tenant->id);

        $this->assertSame('75.00', $dashboard['overdue_amount']);
    }

    public function test_inventory_value_sums_every_warehouses_stock_value(): void
    {
        $tenant = Tenant::factory()->create();
        Warehouse::factory()->recycle($tenant)->create(['stock_value' => 1000]);
        Warehouse::factory()->recycle($tenant)->create(['stock_value' => 500]);

        $dashboard = $this->service()->generate($tenant->id);

        $this->assertSame('1500.00', $dashboard['inventory_value']);
    }

    public function test_critical_expiry_count_only_counts_batches_within_the_threshold(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['exp_date' => now()->addDays(10), 'qty_cartons' => 5]);
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['exp_date' => now()->addDays(200), 'qty_cartons' => 5]);
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['exp_date' => now()->addDays(5), 'qty_cartons' => 0]); // exhausted, doesn't count

        $dashboard = $this->service()->generate($tenant->id);

        $this->assertSame(1, $dashboard['critical_expiry_count']);
    }

    public function test_pending_deliveries_excludes_delivered_and_failed(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        Delivery::factory()->recycle([$tenant, $customer])->for($order, 'salesOrder')->create(['status' => 'pending']);

        $order2 = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        Delivery::factory()->recycle([$tenant, $customer])->for($order2, 'salesOrder')->create(['status' => 'delivered']);

        $dashboard = $this->service()->generate($tenant->id);

        $this->assertSame(1, $dashboard['pending_deliveries']);
    }

    public function test_collected_amount_sums_this_months_collections(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create();
        Collection::factory()->recycle([$tenant, $customer, $invoice])->create(['amount' => 60, 'payment_date' => now()]);

        $dashboard = $this->service()->generate($tenant->id);

        $this->assertSame('60.00', $dashboard['collected_amount']);
    }

    private function service(): DashboardService
    {
        return $this->app->make(DashboardService::class);
    }
}

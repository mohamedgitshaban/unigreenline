<?php

namespace Modules\Sales\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Exceptions\InvalidStatusTransitionException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\UpdateSalesOrderStatusService;
use Tests\TestCase;

class UpdateSalesOrderStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_an_invalid_transition(): void
    {
        [$tenant] = $this->arrange();
        $order = SalesOrder::factory()->recycle($tenant)->create(['status' => 'delivered']);

        $this->expectException(InvalidStatusTransitionException::class);

        $this->service()->transition($order, 'picking');
    }

    public function test_transition_to_invoiced_deducts_stock_and_creates_an_invoice(): void
    {
        [$tenant, $customer, $warehouse, $product, $batch, $order] = $this->orderWithStock();

        $updated = $this->service()->transition($order, 'invoiced');

        $this->assertTrue($updated->stock_deducted);
        $this->assertSame(90, $batch->fresh()->qty_cartons); // 100 - 10
        $this->assertDatabaseHas('invoices', ['so_id' => $order->id, 'customer_id' => $customer->id]);
    }

    public function test_stock_deduction_is_idempotent_across_repeated_transitions(): void
    {
        [$tenant, $customer, $warehouse, $product, $batch, $order] = $this->orderWithStock();

        $this->service()->transition($order, 'invoiced');
        $this->service()->transition($order->fresh(), 'delivered');

        $this->assertSame(90, $batch->fresh()->qty_cartons); // deducted once, not twice
    }

    public function test_transition_posts_a_balanced_journal_entry(): void
    {
        [$tenant, $customer, $warehouse, $product, $batch, $order] = $this->orderWithStock();

        $this->service()->transition($order, 'invoiced');

        $invoice = $order->fresh()->invoices()->first();
        $this->assertDatabaseHas('journal_entries', ['ref' => $invoice->id]);

        $ar = Account::query()->where('tenant_id', $tenant->id)->where('code', '1200')->first();
        $revenue = Account::query()->where('tenant_id', $tenant->id)->where('code', '4100')->first();
        $vat = Account::query()->where('tenant_id', $tenant->id)->where('code', '2300')->first();

        $this->assertSame((string) $invoice->total, $ar->fresh()->balance);
        $this->assertSame((string) $invoice->subtotal, $revenue->fresh()->balance);
        $this->assertSame((string) $invoice->tax_amount, $vat->fresh()->balance);
    }

    public function test_invoice_discount_is_taken_off_revenue_so_the_entry_balances(): void
    {
        [$tenant, $customer, $warehouse, $product, $batch, $order] = $this->orderWithStock();
        $order->update(['invoice_discount' => 14, 'total' => 727]);

        $this->service()->transition($order, 'delivered');

        $ar = Account::query()->where('tenant_id', $tenant->id)->where('code', '1200')->first();
        $revenue = Account::query()->where('tenant_id', $tenant->id)->where('code', '4100')->first();
        $vat = Account::query()->where('tenant_id', $tenant->id)->where('code', '2300')->first();

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('727.00', $ar->fresh()->balance);
        $this->assertSame('636.00', $revenue->fresh()->balance);
        $this->assertSame('91.00', $vat->fresh()->balance);
    }

    public function test_invoicing_a_credit_order_increases_the_customers_balance(): void
    {
        [$tenant, $customer, $warehouse, $product, $batch, $order] = $this->orderWithStock(payType: 'credit');

        $this->service()->transition($order, 'invoiced');

        $invoice = $order->fresh()->invoices()->first();
        $this->assertSame((string) $invoice->total, $customer->fresh()->balance);
    }

    public function test_invoicing_a_cash_order_does_not_change_the_customers_balance(): void
    {
        [$tenant, $customer, $warehouse, $product, $batch, $order] = $this->orderWithStock(payType: 'cash');

        $this->service()->transition($order, 'invoiced');

        $this->assertSame('0.00', $customer->fresh()->balance);
    }

    public function test_going_straight_to_delivered_from_picking_also_auto_invoices(): void
    {
        [$tenant, $customer, $warehouse, $product, $batch, $order] = $this->orderWithStock(status: 'picking');

        $updated = $this->service()->transition($order, 'delivered');

        $this->assertDatabaseHas('invoices', ['so_id' => $order->id]);
        $this->assertDatabaseHas('deliveries', ['so_id' => $order->id, 'status' => 'delivered']);
        $this->assertNotNull($updated->delivery->delivered_at);
    }

    public function test_transitioning_from_invoiced_to_delivered_does_not_create_a_second_invoice(): void
    {
        [$tenant, $customer, $warehouse, $product, $batch, $order] = $this->orderWithStock();

        $afterInvoiced = $this->service()->transition($order, 'invoiced');
        $this->service()->transition($afterInvoiced, 'delivered');

        $this->assertSame(1, $order->fresh()->invoices()->count());
    }

    public function test_insufficient_stock_at_transition_time_rejects_and_changes_nothing(): void
    {
        [$tenant, $customer, $warehouse, $product, $batch, $order] = $this->orderWithStock();

        // Stock gets consumed by something else after the order was created.
        $batch->update(['qty_cartons' => 2]);

        try {
            $this->service()->transition($order, 'invoiced');
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame('draft', $order->fresh()->status);
        $this->assertFalse((bool) $order->fresh()->stock_deducted);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_records_an_audit_log_entry_for_the_status_change(): void
    {
        [$tenant, $customer, $warehouse, $product, $batch, $order] = $this->orderWithStock();

        $this->service()->transition($order, 'picking');

        $this->assertDatabaseHas('audit_log', [
            'module' => 'sales',
            'entity_type' => 'SalesOrder',
            'entity_id' => $order->id,
            'operation' => 'UPDATE',
        ]);
    }

    /**
     * @return array{0: Tenant, 1: Customer, 2: Warehouse, 3: Product, 4: InventoryBatch, 5: SalesOrder}
     */
    private function orderWithStock(string $payType = 'cash', string $status = 'draft'): array
    {
        $tenant = Tenant::factory()->create();
        $this->seedAccounts($tenant);
        $customer = Customer::factory()->recycle($tenant)->create(['credit_limit' => 500000, 'balance' => 0]);
        $rep = User::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create(['carton_qty' => 20, 'tax_pct' => 14]);
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(100)->create();

        $order = SalesOrder::factory()->recycle([$tenant, $customer, $warehouse])->create([
            'sales_rep_id' => $rep->id,
            'pay_type' => $payType,
            'status' => $status,
            'subtotal' => 650,
            'tax_amount' => 91,
            'total' => 741,
        ]);

        SalesOrderLine::factory()->for($order, 'salesOrder')->create([
            'product_id' => $product->id,
            'qty' => 10,
            'unit' => 'Carton',
            'unit_price' => 65,
            'free_qty' => 0,
            'subtotal' => 650,
        ]);

        return [$tenant, $customer, $warehouse, $product, $batch, $order];
    }

    private function arrange(): array
    {
        return [Tenant::factory()->create()];
    }

    private function seedAccounts(Tenant $tenant): void
    {
        foreach ([
            ['code' => '1200', 'type' => 'Asset'],
            ['code' => '4100', 'type' => 'Revenue'],
            ['code' => '2300', 'type' => 'Liability'],
        ] as $account) {
            Account::factory()->recycle($tenant)->create($account);
        }
    }

    private function service(): UpdateSalesOrderStatusService
    {
        return $this->app->make(UpdateSalesOrderStatusService::class);
    }
}

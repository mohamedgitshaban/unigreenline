<?php

namespace Modules\Sales\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Exceptions\CreditLimitExceededException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CreateSalesOrderService;
use Tests\TestCase;

class CreateSalesOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_line_and_order_totals_correctly(): void
    {
        [$tenant, $customer, $rep, $warehouse] = $this->arrange();
        $product = Product::factory()->recycle($tenant)->create([
            'carton_qty' => 20, 'tax_pct' => 14,
        ]);
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(100)->create();

        $order = $this->service()->create($this->orderData($tenant, $customer, $rep, $warehouse, [
            ['product_id' => $product->id, 'qty' => 10, 'unit' => 'Carton', 'unit_price' => 65, 'discount_pct' => 10],
        ]));

        // subtotal = 10 * 65 * 0.9 = 585.00; tax = 585 * 0.14 = 81.90; total = 666.90
        $this->assertSame('585.00', $order->subtotal);
        $this->assertSame('81.90', $order->tax_amount);
        $this->assertSame('666.90', $order->total);
    }

    public function test_free_qty_does_not_add_revenue_but_consumes_stock(): void
    {
        [$tenant, $customer, $rep, $warehouse] = $this->arrange();
        $product = Product::factory()->recycle($tenant)->create(['carton_qty' => 20]);
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(15)->create();

        // 10 paid + 5 free = 15 cartons needed, exactly what's available.
        $order = $this->service()->create($this->orderData($tenant, $customer, $rep, $warehouse, [
            ['product_id' => $product->id, 'qty' => 10, 'unit' => 'Carton', 'unit_price' => 65, 'free_qty' => 5],
        ]));

        $this->assertSame('650.00', $order->subtotal); // free units contribute no revenue
        $this->assertSame(15, $batch->fresh()->qty_cartons); // not yet deducted at creation time
    }

    public function test_pack_unit_line_rounds_up_to_whole_cartons_for_the_stock_check(): void
    {
        [$tenant, $customer, $rep, $warehouse] = $this->arrange();
        $product = Product::factory()->recycle($tenant)->create(['carton_qty' => 20]);
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(2)->create();

        // 45 packs / 20 per carton = ceil(2.25) = 3 cartons needed, but only 2 available.
        try {
            $this->service()->create($this->orderData($tenant, $customer, $rep, $warehouse, [
                ['product_id' => $product->id, 'qty' => 45, 'unit' => 'Pack', 'unit_price' => 5],
            ]));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(3, $e->shortfalls[0]['needed']);
            $this->assertSame(2, $e->shortfalls[0]['available']);
        }
    }

    public function test_insufficient_stock_rejects_the_order_and_writes_nothing(): void
    {
        [$tenant, $customer, $rep, $warehouse] = $this->arrange();
        $product = Product::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(5)->create();

        try {
            $this->service()->create($this->orderData($tenant, $customer, $rep, $warehouse, [
                ['product_id' => $product->id, 'qty' => 6, 'unit' => 'Carton', 'unit_price' => 65],
            ]));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_credit_order_exceeding_credit_limit_is_rejected(): void
    {
        [$tenant, $customer, $rep, $warehouse] = $this->arrange();
        $customer->forceFill(['credit_limit' => 1000, 'balance' => 900])->save();
        $product = Product::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(100)->create();

        try {
            $this->service()->create($this->orderData($tenant, $customer, $rep, $warehouse, [
                ['product_id' => $product->id, 'qty' => 10, 'unit' => 'Carton', 'unit_price' => 65],
            ], payType: 'credit'));
            $this->fail('Expected CreditLimitExceededException.');
        } catch (CreditLimitExceededException $e) {
            $this->assertSame($customer->id, $e->customerId);
        }

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_cash_order_ignores_the_credit_limit(): void
    {
        [$tenant, $customer, $rep, $warehouse] = $this->arrange();
        $customer->forceFill(['credit_limit' => 100, 'balance' => 100])->save();
        $product = Product::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(100)->create();

        $order = $this->service()->create($this->orderData($tenant, $customer, $rep, $warehouse, [
            ['product_id' => $product->id, 'qty' => 10, 'unit' => 'Carton', 'unit_price' => 65],
        ], payType: 'cash'));

        $this->assertInstanceOf(SalesOrder::class, $order);
    }

    public function test_crossing_85_percent_of_credit_limit_creates_a_warning_notification(): void
    {
        [$tenant, $customer, $rep, $warehouse] = $this->arrange();
        $customer->forceFill(['credit_limit' => 1000, 'balance' => 800])->save(); // 80% used
        $product = Product::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(100)->create();

        // +100 => 900/1000 = 90%, crosses the 85% threshold.
        $this->service()->create($this->orderData($tenant, $customer, $rep, $warehouse, [
            ['product_id' => $product->id, 'qty' => 1, 'unit' => 'Carton', 'unit_price' => 100],
        ], payType: 'credit'));

        $this->assertSame(1, Notification::query()->where('type', 'credit_warning')->count());
    }

    public function test_staying_below_85_percent_does_not_create_a_notification(): void
    {
        [$tenant, $customer, $rep, $warehouse] = $this->arrange();
        $customer->forceFill(['credit_limit' => 1000, 'balance' => 0])->save();
        $product = Product::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(100)->create();

        $this->service()->create($this->orderData($tenant, $customer, $rep, $warehouse, [
            ['product_id' => $product->id, 'qty' => 1, 'unit' => 'Carton', 'unit_price' => 100],
        ], payType: 'credit'));

        $this->assertSame(0, Notification::query()->where('type', 'credit_warning')->count());
    }

    public function test_creates_an_audit_log_entry_atomically_with_the_order(): void
    {
        [$tenant, $customer, $rep, $warehouse] = $this->arrange();
        $product = Product::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(100)->create();

        $order = $this->service()->create($this->orderData($tenant, $customer, $rep, $warehouse, [
            ['product_id' => $product->id, 'qty' => 10, 'unit' => 'Carton', 'unit_price' => 65],
        ]));

        $this->assertDatabaseHas('audit_log', [
            'module' => 'sales',
            'entity_type' => 'SalesOrder',
            'entity_id' => $order->id,
            'operation' => 'INSERT',
        ]);
    }

    /**
     * @return array{0: Tenant, 1: Customer, 2: User, 3: Warehouse}
     */
    private function arrange(): array
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create(['credit_limit' => 500000, 'balance' => 0]);
        $rep = User::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();

        return [$tenant, $customer, $rep, $warehouse];
    }

    private function orderData(Tenant $tenant, Customer $customer, User $rep, Warehouse $warehouse, array $lines, string $payType = 'cash'): array
    {
        return [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'sales_rep_id' => $rep->id,
            'warehouse_id' => $warehouse->id,
            'pay_type' => $payType,
            'lines' => $lines,
        ];
    }

    private function service(): CreateSalesOrderService
    {
        return $this->app->make(CreateSalesOrderService::class);
    }
}

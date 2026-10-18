<?php

namespace Modules\Sales\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Exceptions\UnknownAccountCodeException;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\UpdateSalesOrderStatusService;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

class CostOfGoodsSoldTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    public function test_delivering_charges_the_cost_of_the_stock_that_left_to_cost_of_goods_sold(): void
    {
        [$tenant, $order] = $this->order(function (Tenant $tenant, Product $product, Warehouse $warehouse) {
            // FEFO takes the earlier batch first: 6 cartons at 40, then 4 at 55.
            InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->expiringOn('2027-01-01')->withQtyCartons(6)->create(['cost_per_carton' => '40.00']);
            InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->expiringOn('2028-01-01')->withQtyCartons(20)->create(['cost_per_carton' => '55.00']);
        });

        $this->service()->transition($order, 'delivered');

        // 6 × 40 + 4 × 55 = 460.
        $this->assertSame('460.00', $this->balance($tenant, '5100'));
        $this->assertSame('-460.00', $this->balance($tenant, '1300'));
        $this->assertDatabaseHas('journal_entries', ['ref' => $order->id, 'type' => 'system']);
    }

    public function test_it_is_posted_once_even_when_the_order_moves_on_to_delivered_after_invoicing(): void
    {
        [$tenant, $order] = $this->order(fn (Tenant $t, Product $p, Warehouse $w) => InventoryBatch::factory()->recycle([$t, $p, $w])->withQtyCartons(50)->create(['cost_per_carton' => '10.00']));

        $this->service()->transition($order, 'invoiced');
        $this->service()->transition($order->fresh(), 'delivered');

        $this->assertSame('100.00', $this->balance($tenant, '5100'));
    }

    public function test_free_gift_stock_costs_nothing_so_posts_no_cost_of_goods_sold(): void
    {
        [$tenant, $order] = $this->order(
            fn (Tenant $t, Product $p, Warehouse $w) => InventoryBatch::factory()->recycle([$t, $p, $w])->withQtyCartons(50)->create(['cost_per_carton' => '0.00', 'is_free_gift' => true]),
            gift: true,
        );

        $this->service()->transition($order, 'delivered');

        $this->assertSame('0.00', $this->balance($tenant, '5100'));
    }

    public function test_a_missing_cost_of_goods_sold_account_stops_the_delivery_instead_of_losing_the_cost(): void
    {
        [$tenant, $order] = $this->order(fn (Tenant $t, Product $p, Warehouse $w) => InventoryBatch::factory()->recycle([$t, $p, $w])->withQtyCartons(50)->create(['cost_per_carton' => '10.00']));
        Account::query()->where('tenant_id', $tenant->id)->where('code', '5100')->delete();

        $this->expectException(UnknownAccountCodeException::class);

        try {
            $this->service()->transition($order, 'delivered');
        } finally {
            // Nothing half-done: the whole transition rolled back.
            $this->assertSame('draft', $order->fresh()->status);
            $this->assertFalse((bool) $order->fresh()->stock_deducted);
        }
    }

    /**
     * @param  callable(Tenant, Product, Warehouse): mixed  $stock
     * @return array{0: Tenant, 1: SalesOrder}
     */
    private function order(callable $stock, bool $gift = false): array
    {
        $tenant = Tenant::factory()->create();
        $this->ensureLedgerAccounts($tenant);
        $customer = Customer::factory()->recycle($tenant)->create(['credit_limit' => 500000, 'balance' => 0]);
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create(['tax_pct' => 0]);
        $stock($tenant, $product, $warehouse);

        $order = SalesOrder::factory()->recycle([$tenant, $customer, $warehouse])->create([
            'sales_rep_id' => User::factory()->recycle($tenant)->create()->id,
            'status' => 'draft', 'subtotal' => 100, 'tax_amount' => 0, 'total' => 100,
        ]);
        SalesOrderLine::factory()->for($order, 'salesOrder')->create([
            'product_id' => $product->id, 'qty' => 10, 'unit' => 'Carton', 'unit_price' => 10, 'free_qty' => 0, 'subtotal' => 100, 'is_gift' => $gift,
        ]);

        return [$tenant, $order];
    }

    private function balance(Tenant $tenant, string $code): string
    {
        return Account::query()->where('tenant_id', $tenant->id)->where('code', $code)->firstOrFail()->balance;
    }

    private function service(): UpdateSalesOrderStatusService
    {
        return $this->app->make(UpdateSalesOrderStatusService::class);
    }
}

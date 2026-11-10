<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * A line's free quantity comes out of gift stock, and its paid quantity out of regular stock, with no gift tick box.
 */
class SalesOrderFreeQuantityTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Customer $customer;

    private Product $product;

    private InventoryBatch $regular;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->customer = Customer::factory()->recycle($this->tenant)->create(['credit_limit' => 100000, 'balance' => 0]);
        $this->product = Product::factory()->recycle($this->tenant)->create(['carton_qty' => 12]);
        $this->regular = InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->warehouse])->withQtyCartons(10)->create();
        $this->ensureLedgerAccounts($this->tenant);
        $this->actingAsRole('Sales Manager');
    }

    public function test_the_free_quantity_is_taken_from_gift_stock_and_the_paid_quantity_from_regular_stock(): void
    {
        $gift = $this->giftStock(2);

        $id = $this->order(qty: 5, freeQty: 1)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/sales-orders/{$id}/status", ['status' => 'invoiced'])->assertOk();

        $this->assertSame(5, $this->regular->fresh()->qty_cartons);
        $this->assertSame(1, $gift->fresh()->qty_cartons);
    }

    public function test_free_packs_and_paid_packs_round_up_to_cartons_separately(): void
    {
        $gift = $this->giftStock(2);

        $id = $this->order(qty: 10, freeQty: 2, unit: 'Pack')->assertCreated()->json('data.id');
        $this->putJson("/api/v1/sales-orders/{$id}/status", ['status' => 'invoiced'])->assertOk();

        $this->assertSame(9, $this->regular->fresh()->qty_cartons);
        $this->assertSame(1, $gift->fresh()->qty_cartons);
    }

    public function test_an_order_with_a_free_quantity_is_refused_when_the_gift_stock_is_short(): void
    {
        $this->giftStock(1);

        $this->order(qty: 2, freeQty: 3)
            ->assertUnprocessable()
            ->assertJsonPath('shortfalls.0.gift', true)
            ->assertJsonPath('shortfalls.0.needed', 3)
            ->assertJsonPath('shortfalls.0.available', 1)
            ->assertJsonPath('shortfalls.0.product_name', $this->product->name);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_a_product_with_no_gift_stock_cannot_be_sold_with_a_free_quantity(): void
    {
        $this->order(qty: 2, freeQty: 1)->assertUnprocessable()->assertJsonPath('shortfalls.0.gift', true);
    }

    public function test_the_warehouse_manager_and_the_order_owner_are_told_when_gift_stock_runs_out(): void
    {
        $manager = User::factory()->recycle($this->tenant)->create();
        $this->warehouse->update(['manager_id' => $manager->id]);

        $this->order(qty: 2, freeQty: 1)->assertUnprocessable();

        $this->assertSame(2, Notification::query()->where('type', 'gift_stock_short')->where('link_id', $this->product->id)->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $manager->id, 'type' => 'gift_stock_short', 'unread' => 1]);
    }

    public function test_a_plain_shortage_of_regular_stock_does_not_raise_a_gift_alert(): void
    {
        $this->order(qty: 50, freeQty: 0)->assertUnprocessable();

        $this->assertDatabaseMissing('notifications', ['type' => 'gift_stock_short']);
    }

    private function giftStock(int $cartons): InventoryBatch
    {
        return InventoryBatch::factory()
            ->recycle([$this->tenant, $this->product, $this->warehouse])
            ->withQtyCartons($cartons)
            ->create(['is_free_gift' => true, 'cost_per_carton' => 0, 'batch_no' => 'GIFT-1']);
    }

    private function order(int $qty, int $freeQty, string $unit = 'Carton'): TestResponse
    {
        return $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $this->customer->id,
            'pay_type' => 'credit',
            'lines' => [[
                'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
                'qty' => $qty, 'free_qty' => $freeQty, 'unit' => $unit, 'unit_price' => 100,
            ]],
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        $this->warehouse->users()->attach($user);
        Sanctum::actingAs($user);

        return $user;
    }
}

<?php

namespace Modules\Inventory\Tests\Feature\Http\Warehouses;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Sales\Models\ReturnRecord;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * The lists behind a warehouse's overview cards, what is coming in and what came back, and setting a warehouse up with stock.
 */
class WarehouseListsTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Warehouse Manager');
        $this->warehouse->users()->attach($user);
        Sanctum::actingAs($user);
        $this->travelTo('2026-11-10 10:00:00');
    }

    public function test_the_batches_list_can_be_narrowed_to_the_expired_ones(): void
    {
        $expired = $this->batch(['exp_date' => '2026-10-01', 'batch_no' => 'OLD']);
        $this->batch(['exp_date' => '2026-11-20', 'batch_no' => 'SOON']);
        $this->batch(['exp_date' => '2028-01-01', 'batch_no' => 'FRESH']);

        $this->getJson("/api/v1/warehouses/{$this->warehouse->id}/batches?alert=expired")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expired->id);
    }

    public function test_the_batches_list_can_be_narrowed_to_those_expiring_within_thirty_days(): void
    {
        $this->batch(['exp_date' => '2026-10-01', 'batch_no' => 'OLD']);
        $soon = $this->batch(['exp_date' => '2026-11-20', 'batch_no' => 'SOON']);
        $this->batch(['exp_date' => '2028-01-01', 'batch_no' => 'FRESH']);

        $this->getJson("/api/v1/warehouses/{$this->warehouse->id}/batches?alert=expiring")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $soon->id);
    }

    public function test_the_batches_list_can_be_narrowed_to_products_at_or_below_their_minimum(): void
    {
        $low = Product::factory()->recycle($this->tenant)->create(['min_stock_cartons' => 10]);
        $fine = Product::factory()->recycle($this->tenant)->create(['min_stock_cartons' => 10]);
        $lowBatch = $this->batch(['batch_no' => 'LOW'], $low, 4);
        $this->batch(['batch_no' => 'FINE'], $fine, 40);

        $this->getJson("/api/v1/warehouses/{$this->warehouse->id}/batches?alert=low_stock")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowBatch->id);
    }

    public function test_an_unknown_alert_is_refused(): void
    {
        $this->getJson("/api/v1/warehouses/{$this->warehouse->id}/batches?alert=nonsense")->assertUnprocessable()->assertJsonValidationErrors('alert');
    }

    public function test_the_products_held_in_the_warehouse_are_listed_with_their_cartons(): void
    {
        $low = Product::factory()->recycle($this->tenant)->create(['name' => 'Amoxicillin', 'min_stock_cartons' => 10]);
        $fine = Product::factory()->recycle($this->tenant)->create(['name' => 'Vitamin', 'min_stock_cartons' => 10]);
        $this->batch(['batch_no' => 'A1'], $low, 3);
        $this->batch(['batch_no' => 'A2'], $low, 2);
        $this->batch(['batch_no' => 'V1'], $fine, 40);
        Product::factory()->recycle($this->tenant)->create();

        $this->getJson("/api/v1/warehouses/{$this->warehouse->id}/products")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Amoxicillin')
            ->assertJsonPath('data.0.cartons', 5)
            ->assertJsonPath('data.0.at_or_below_minimum', true)
            ->assertJsonPath('data.1.cartons', 40)
            ->assertJsonPath('data.1.at_or_below_minimum', false);

        $this->getJson("/api/v1/warehouses/{$this->warehouse->id}/products?below_minimum=1")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $low->id);
        $this->getJson("/api/v1/warehouses/{$this->warehouse->id}/products?search=vita")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $fine->id);
    }

    public function test_customer_returns_into_the_warehouse_are_listed_but_returns_to_a_supplier_are_not(): void
    {
        $product = Product::factory()->recycle($this->tenant)->create();
        ReturnRecord::factory()->recycle($this->tenant)->create(['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'type' => 'Damaged', 'restocked' => false, 'qty' => 2]);
        ReturnRecord::factory()->recycle($this->tenant)->create(['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'type' => 'Purchase Return']);
        ReturnRecord::factory()->recycle($this->tenant)->create(['warehouse_id' => Warehouse::factory()->recycle($this->tenant)->create()->id, 'product_id' => $product->id]);

        $this->getJson("/api/v1/warehouses/{$this->warehouse->id}/returns")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'Damaged')
            ->assertJsonPath('data.0.restocked', false)
            ->assertJsonPath('data.0.product.id', $product->id);
    }

    public function test_production_orders_still_to_make_stock_for_the_warehouse_are_listed_as_incoming(): void
    {
        $product = Product::factory()->recycle($this->tenant)->create(['name' => 'Own brand tonic']);
        $bom = Bom::factory()->recycle([$this->tenant, $product])->create();
        $open = ProductionOrder::factory()->recycle([$this->tenant, $bom, $this->warehouse])->create(['status' => 'confirmed', 'output_qty_cartons' => 8, 'number' => 'MO00001']);
        ProductionOrder::factory()->recycle([$this->tenant, $bom, $this->warehouse])->completed()->create();

        $this->getJson("/api/v1/warehouses/{$this->warehouse->id}/incoming?source=production")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.number', 'MO00001')
            ->assertJsonPath('data.0.total_cartons', 8)
            ->assertJsonPath('data.0.product.name', 'Own brand tonic');
    }

    public function test_a_warehouse_is_set_up_with_its_opening_stock_in_one_call(): void
    {
        $this->ensureLedgerAccounts($this->tenant);
        $first = Product::factory()->recycle($this->tenant)->create();
        $second = Product::factory()->recycle($this->tenant)->create();

        $this->postJson("/api/v1/warehouses/{$this->warehouse->id}/opening-stock", ['items' => [
            ['product_id' => $first->id, 'batch_no' => 'OB-1', 'qty_cartons' => 10, 'cost_per_carton' => 20, 'exp_date' => '2028-01-31'],
            ['product_id' => $second->id, 'batch_no' => 'OB-2', 'qty_cartons' => 5, 'cost_per_carton' => 40, 'exp_date' => '2028-02-28'],
        ]])->assertCreated()->assertJsonCount(2, 'data');

        $this->assertSame(10, InventoryBatch::query()->where('batch_no', 'OB-1')->value('qty_cartons'));
        $this->assertSame(5, InventoryBatch::query()->where('batch_no', 'OB-2')->value('qty_cartons'));
        $this->assertSame('400.00', Account::query()->where('tenant_id', $this->tenant->id)->where('code', '3300')->first()->balance);
    }

    public function test_opening_stock_with_one_bad_line_enters_nothing(): void
    {
        $this->ensureLedgerAccounts($this->tenant);
        $product = Product::factory()->recycle($this->tenant)->create();

        $this->postJson("/api/v1/warehouses/{$this->warehouse->id}/opening-stock", ['items' => [
            ['product_id' => $product->id, 'batch_no' => 'OB-1', 'qty_cartons' => 10, 'cost_per_carton' => 20, 'exp_date' => '2028-01-31'],
            ['product_id' => $product->id, 'batch_no' => 'OB-2', 'qty_cartons' => 0, 'cost_per_carton' => 20, 'exp_date' => '2028-01-31'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.qty_cartons');

        $this->assertDatabaseCount('inventory_batches', 0);
    }

    public function test_opening_stock_cannot_be_entered_into_a_warehouse_the_user_does_not_work_in(): void
    {
        $other = Warehouse::factory()->recycle($this->tenant)->create();
        $product = Product::factory()->recycle($this->tenant)->create();

        $this->postJson("/api/v1/warehouses/{$other->id}/opening-stock", ['items' => [
            ['product_id' => $product->id, 'batch_no' => 'OB-1', 'qty_cartons' => 10, 'cost_per_carton' => 20, 'exp_date' => '2028-01-31'],
        ]])->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function batch(array $attributes, ?Product $product = null, int $cartons = 10): InventoryBatch
    {
        return InventoryBatch::factory()
            ->recycle([$this->tenant, $product ?? Product::factory()->recycle($this->tenant)->create(), $this->warehouse])
            ->withQtyCartons($cartons)
            ->create($attributes);
    }
}

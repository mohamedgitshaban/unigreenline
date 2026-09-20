<?php

namespace Modules\Inventory\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\GoodsReceiptService;
use Tests\TestCase;

class GoodsReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_new_batch_when_the_batch_number_is_new(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();

        $batch = $this->service()->receive([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-NEW',
            'qty_cartons' => 40,
            'cost_per_carton' => 120.50,
            'exp_date' => '2027-12-31',
        ]);

        $this->assertDatabaseHas('inventory_batches', [
            'id' => $batch->id,
            'batch_no' => 'BATCH-NEW',
            'qty_cartons' => 40,
        ]);
    }

    public function test_tops_up_an_existing_batch_with_the_same_batch_number(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();

        $existing = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['batch_no' => 'BATCH-001', 'qty_cartons' => 10]);

        $batch = $this->service()->receive([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-001',
            'qty_cartons' => 15,
            'cost_per_carton' => 100,
            'exp_date' => '2027-01-01',
        ]);

        $this->assertSame($existing->id, $batch->id);
        $this->assertSame(25, $batch->qty_cartons);
        $this->assertSame(1, InventoryBatch::query()->where('batch_no', 'BATCH-001')->count());
    }

    public function test_a_different_batch_number_for_the_same_product_and_warehouse_creates_a_separate_row(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();

        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['batch_no' => 'BATCH-001', 'qty_cartons' => 10, 'exp_date' => '2027-01-01']);

        $this->service()->receive([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-002',
            'qty_cartons' => 5,
            'cost_per_carton' => 100,
            'exp_date' => '2028-06-01',
        ]);

        $this->assertSame(2, InventoryBatch::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->count());
    }

    public function test_recalculates_warehouse_stock_value_after_receiving(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create(['pack_cost_price' => 10, 'carton_qty' => 3]);
        $warehouse = Warehouse::factory()->recycle($tenant)->create();

        $this->service()->receive([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-001',
            'qty_cartons' => 10,
            'cost_per_carton' => 100,
            'exp_date' => '2027-01-01',
        ]);

        $this->assertSame('300.00', $warehouse->fresh()->stock_value);
    }

    private function service(): GoodsReceiptService
    {
        return $this->app->make(GoodsReceiptService::class);
    }
}

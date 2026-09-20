<?php

namespace Modules\Inventory\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\TransferStockService;
use Tests\TestCase;

class TransferStockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_new_batch_in_the_destination_warehouse(): void
    {
        [$tenant, $product, $from, $to] = $this->arrange();
        $source = InventoryBatch::factory()->recycle([$tenant, $product, $from])
            ->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 50, 'exp_date' => '2027-06-01']);

        $this->service()->transfer($this->data($tenant, $product, $from, $to, 'BATCH-X', 20));

        $this->assertSame(30, $source->fresh()->qty_cartons);
        $this->assertDatabaseHas('inventory_batches', [
            'warehouse_id' => $to->id, 'batch_no' => 'BATCH-X', 'qty_cartons' => 20, 'exp_date' => '2027-06-01',
        ]);
    }

    public function test_tops_up_an_existing_destination_batch(): void
    {
        [$tenant, $product, $from, $to] = $this->arrange();
        $source = InventoryBatch::factory()->recycle([$tenant, $product, $from])
            ->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 50]);
        $destination = InventoryBatch::factory()->recycle([$tenant, $product, $to])
            ->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 10]);

        $this->service()->transfer($this->data($tenant, $product, $from, $to, 'BATCH-X', 20));

        $this->assertSame(30, $destination->fresh()->qty_cartons);
        $this->assertSame(1, InventoryBatch::query()->where('warehouse_id', $to->id)->where('batch_no', 'BATCH-X')->count());
    }

    public function test_deletes_the_source_batch_when_it_reaches_zero(): void
    {
        [$tenant, $product, $from, $to] = $this->arrange();
        $source = InventoryBatch::factory()->recycle([$tenant, $product, $from])
            ->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 20]);

        $this->service()->transfer($this->data($tenant, $product, $from, $to, 'BATCH-X', 20));

        $this->assertModelMissing($source);
    }

    public function test_insufficient_stock_in_source_batch_is_rejected(): void
    {
        [$tenant, $product, $from, $to] = $this->arrange();
        InventoryBatch::factory()->recycle([$tenant, $product, $from])
            ->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 5]);

        try {
            $this->service()->transfer($this->data($tenant, $product, $from, $to, 'BATCH-X', 6));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(6, $e->shortfalls[0]['needed']);
            $this->assertSame(5, $e->shortfalls[0]['available']);
        }

        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_recalculates_both_warehouses_stock_values(): void
    {
        [$tenant, $product, $from, $to] = $this->arrange();
        $product->forceFill(['pack_cost_price' => 10, 'carton_qty' => 2])->save(); // cost_price = 20/carton
        InventoryBatch::factory()->recycle([$tenant, $product, $from])->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 50]);
        $from->recalculateStockValue();
        $to->recalculateStockValue();

        $this->service()->transfer($this->data($tenant, $product, $from, $to, 'BATCH-X', 20));

        $this->assertSame('600.00', $from->fresh()->stock_value); // (50-20)*20
        $this->assertSame('400.00', $to->fresh()->stock_value); // 20*20
    }

    public function test_records_an_audit_log_entry(): void
    {
        [$tenant, $product, $from, $to] = $this->arrange();
        InventoryBatch::factory()->recycle([$tenant, $product, $from])->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 50]);

        $transfer = $this->service()->transfer($this->data($tenant, $product, $from, $to, 'BATCH-X', 20));

        $this->assertDatabaseHas('audit_log', [
            'module' => 'inventory',
            'entity_type' => 'Transfer',
            'entity_id' => $transfer->id,
            'operation' => 'INSERT',
        ]);
    }

    /**
     * @return array{0: Tenant, 1: Product, 2: Warehouse, 3: Warehouse}
     */
    private function arrange(): array
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $from = Warehouse::factory()->recycle($tenant)->create();
        $to = Warehouse::factory()->recycle($tenant)->create();

        return [$tenant, $product, $from, $to];
    }

    private function data(Tenant $tenant, Product $product, Warehouse $from, Warehouse $to, string $batchNo, int $qty): array
    {
        return [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'batch_no' => $batchNo,
            'qty_cartons' => $qty,
        ];
    }

    private function service(): TransferStockService
    {
        return $this->app->make(TransferStockService::class);
    }
}

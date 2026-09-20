<?php

namespace Modules\Sales\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;
use Modules\Sales\Exceptions\ReturnBatchNotFoundException;
use Modules\Sales\Services\ProcessReturnService;
use Tests\TestCase;

class ProcessReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_return_with_restocked_true_adds_stock_back(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 10]);

        $this->service()->process([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-X', 'type' => 'Sales Return', 'qty' => 5, 'unit' => 'Carton',
            'amount' => 100, 'restocked' => true,
        ]);

        $this->assertSame(15, $batch->fresh()->qty_cartons);
    }

    public function test_sales_return_with_restocked_false_does_not_move_stock(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 10]);

        $this->service()->process([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-X', 'type' => 'Damaged', 'qty' => 5, 'unit' => 'Carton',
            'amount' => 100, 'restocked' => false,
        ]);

        $this->assertSame(10, $batch->fresh()->qty_cartons);
    }

    public function test_purchase_return_always_deducts_stock_even_if_restocked_is_true(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();
        $supplier = Supplier::factory()->recycle($tenant)->create();
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 10]);

        // restocked=true is deliberately wrong input here — Purchase Return
        // must still deduct, proving the type check wins over the flag.
        $this->service()->process([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'batch_no' => 'BATCH-X', 'type' => 'Purchase Return',
            'qty' => 5, 'unit' => 'Carton', 'amount' => 100, 'restocked' => true,
        ]);

        $this->assertSame(5, $batch->fresh()->qty_cartons);
    }

    public function test_purchase_return_with_insufficient_stock_is_rejected(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();
        $supplier = Supplier::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 3]);

        $this->expectException(InsufficientStockException::class);

        $this->service()->process([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'batch_no' => 'BATCH-X', 'type' => 'Purchase Return',
            'qty' => 5, 'unit' => 'Carton', 'amount' => 100,
        ]);
    }

    public function test_restocking_into_a_nonexistent_batch_is_rejected(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();

        $this->expectException(ReturnBatchNotFoundException::class);

        $this->service()->process([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'batch_no' => 'NO-SUCH-BATCH', 'type' => 'Sales Return', 'qty' => 5, 'unit' => 'Carton',
            'amount' => 100, 'restocked' => true,
        ]);
    }

    public function test_records_an_audit_log_entry(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();

        $return = $this->service()->process([
            'tenant_id' => $tenant->id, 'type' => 'Damaged', 'qty' => 5, 'unit' => 'Carton', 'amount' => 100,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'module' => 'sales',
            'entity_type' => 'ReturnRecord',
            'entity_id' => $return->id,
            'operation' => 'INSERT',
        ]);
    }

    /**
     * @return array{0: Tenant, 1: Product, 2: Warehouse}
     */
    private function arrange(): array
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();

        return [$tenant, $product, $warehouse];
    }

    private function service(): ProcessReturnService
    {
        return $this->app->make(ProcessReturnService::class);
    }
}

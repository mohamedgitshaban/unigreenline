<?php

namespace Modules\Inventory\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\FefoStockService;
use Tests\TestCase;

class FefoStockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_batch_leaves_the_remainder_in_place(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();

        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->withQtyCartons(100)
            ->create();

        $this->service()->deduct([
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 30],
        ]);

        $this->assertSame(70, $batch->fresh()->qty_cartons);
    }

    public function test_draws_from_multiple_batches_in_expiry_order(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();

        $soonest = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->expiringOn('2027-01-01')->withQtyCartons(20)->create();
        $later = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->expiringOn('2028-01-01')->withQtyCartons(50)->create();

        $this->service()->deduct([
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 25],
        ]);

        $this->assertSame(0, $soonest->fresh()->qty_cartons);
        $this->assertSame(45, $later->fresh()->qty_cartons);
    }

    public function test_ties_on_expiry_date_break_by_earliest_received_date(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();

        $receivedLater = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->expiringOn('2027-06-01')->receivedOn('2026-06-01')->withQtyCartons(10)->create();
        $receivedEarlier = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->expiringOn('2027-06-01')->receivedOn('2026-01-01')->withQtyCartons(10)->create();

        $this->service()->deduct([
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 10],
        ]);

        $this->assertSame(0, $receivedEarlier->fresh()->qty_cartons);
        $this->assertSame(10, $receivedLater->fresh()->qty_cartons);
    }

    public function test_exact_exhaustion_of_a_batch_does_not_touch_the_next_batch(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();

        $first = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->expiringOn('2027-01-01')->withQtyCartons(20)->create();
        $second = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->expiringOn('2028-01-01')->withQtyCartons(20)->create();

        $this->service()->deduct([
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 20],
        ]);

        $this->assertSame(0, $first->fresh()->qty_cartons);
        $this->assertSame(20, $second->fresh()->qty_cartons);
    }

    public function test_insufficient_stock_across_all_batches_is_rejected_before_writing_anything(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();

        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->withQtyCartons(15)->create();

        try {
            $this->service()->deduct([
                ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 16],
            ]);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertSame([[
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'needed' => 16,
                'available' => 15,
            ]], $e->shortfalls);
        }

        $this->assertSame(15, $batch->fresh()->qty_cartons);
    }

    public function test_one_unsatisfiable_line_rejects_the_whole_order_leaving_other_lines_untouched(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();
        $otherProduct = Product::factory()->recycle([$tenant])->create();

        $satisfiableBatch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->withQtyCartons(50)->create();
        $unsatisfiableBatch = InventoryBatch::factory()->recycle([$tenant, $otherProduct, $warehouse])
            ->withQtyCartons(5)->create();

        try {
            $this->service()->deduct([
                ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 10],
                ['product_id' => $otherProduct->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 999],
            ]);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame(50, $satisfiableBatch->fresh()->qty_cartons);
        $this->assertSame(5, $unsatisfiableBatch->fresh()->qty_cartons);
    }

    public function test_ignores_batches_of_the_same_product_in_a_different_warehouse(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();
        $otherWarehouse = Warehouse::factory()->recycle([$tenant])->create();

        InventoryBatch::factory()->recycle([$tenant, $product])->for($otherWarehouse, 'warehouse')
            ->withQtyCartons(100)->create();

        try {
            $this->service()->deduct([
                ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 1],
            ]);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(0, $e->shortfalls[0]['available']);
        }
    }

    public function test_recalculates_warehouse_stock_value_after_deduction(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();
        $product->forceFill(['pack_cost_price' => 10, 'carton_qty' => 2])->save(); // cost_price = 20/carton

        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->withQtyCartons(100)->create();
        $warehouse->recalculateStockValue();
        $this->assertSame('2000.00', $warehouse->fresh()->stock_value);

        $this->service()->deduct([
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 40],
        ]);

        $this->assertSame('1200.00', $warehouse->fresh()->stock_value);
    }

    public function test_check_availability_reports_shortfalls_without_writing_anything(): void
    {
        [$tenant, $product, $warehouse] = $this->arrange();
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->withQtyCartons(5)->create();

        $shortfalls = $this->service()->checkAvailability([
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 8],
        ]);

        $this->assertCount(1, $shortfalls);
        $this->assertSame(5, $batch->fresh()->qty_cartons);
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

    private function service(): FefoStockService
    {
        return $this->app->make(FefoStockService::class);
    }
}

<?php

namespace Modules\Inventory\Tests\Feature\Services;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\FefoStockService;
use Tests\TestCase;

/**
 * Spec §11: load-test the FEFO query path with realistic batch volumes.
 * Correctness of the deduction algorithm itself (partial batch, ties,
 * insufficient stock, ...) is already covered by FefoStockServiceTest with
 * a handful of rows — that's deliberately not repeated here. This file
 * answers two different questions: (1) does the query still hit
 * inventory_batches_fefo_index once the table is thousands of rows deep,
 * instead of degrading to a full scan + filesort, and (2) does
 * lockForUpdate() actually serialize concurrent writers instead of letting
 * two simultaneous sales orders double-spend the same batch.
 *
 * Rows are inserted via a raw bulk DB::table()->insert() rather than the
 * factory — creating ~2,000 rows through individual Eloquent creates would
 * make this file slow enough that nobody runs it locally.
 */
class FefoLoadTest extends TestCase
{
    use RefreshDatabase;

    private const NOISE_PRODUCTS = 80;

    private const WAREHOUSES = 3;

    private const BATCHES_PER_PAIR = 8;

    public function test_deduction_query_uses_the_fefo_index_at_realistic_volume(): void
    {
        [, $product, $warehouse] = $this->seedRealisticVolume();

        $query = InventoryBatch::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('qty_cartons', '>', 0)
            ->orderBy('exp_date')
            ->orderBy('rcv_date');

        $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings())[0];

        $this->assertNotSame('ALL', $plan->type, 'FEFO deduction query fell back to a full table scan at volume.');
        $this->assertSame('inventory_batches_fefo_index', $plan->key);
        $this->assertStringNotContainsString('Using filesort', $plan->Extra ?? '', 'ORDER BY is no longer satisfied by the index — sorting in memory instead.');
    }

    public function test_availability_sum_query_uses_the_fefo_index_at_realistic_volume(): void
    {
        [, $product, $warehouse] = $this->seedRealisticVolume();

        $query = InventoryBatch::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('qty_cartons', '>', 0)
            ->selectRaw('sum(qty_cartons)');

        $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings())[0];

        $this->assertNotSame('ALL', $plan->type, 'checkAvailability() query fell back to a full table scan at volume.');
        $this->assertSame('inventory_batches_fefo_index', $plan->key);
    }

    public function test_deduction_across_a_deep_batch_chain_stays_fast_and_correct(): void
    {
        [, $product, $warehouse, $deepBatchIds] = $this->seedRealisticVolume(deepChainBatches: 40);

        $start = microtime(true);
        $deductions = $this->service()->deduct([
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'cartons_needed' => 247],
        ]);
        $elapsedMs = (microtime(true) - $start) * 1000;

        // A generous ceiling — this is a regression guard against an
        // accidental full scan or N+1 reappearing, not a strict SLA.
        $this->assertLessThan(1000, $elapsedMs, "FEFO deduction across a deep chain amid ~2,000 rows took {$elapsedMs}ms.");

        // 40 batches of 10 cartons each, earliest-expiring first: 247 spans
        // the first 25 (24 fully exhausted, the 25th takes 7 of its 10).
        $this->assertCount(25, $deductions);
        $this->assertSame(247, array_sum(array_column($deductions, 'cartons_taken')));
        $this->assertSame(0, InventoryBatch::find($deepBatchIds[0])->qty_cartons);
        $this->assertSame(0, InventoryBatch::find($deepBatchIds[23])->qty_cartons);
        $this->assertSame(3, InventoryBatch::find($deepBatchIds[24])->qty_cartons);
        $this->assertSame(10, InventoryBatch::find($deepBatchIds[25])->qty_cartons);
    }

    /**
     * PHPUnit is single-threaded, so "concurrent" here means a second,
     * independent DB connection to the same database — the same mechanism
     * two simultaneous HTTP requests would use. This proves lockForUpdate()
     * actually blocks a second writer rather than merely looking atomic in
     * a single-connection test.
     */
    public function test_concurrent_deductions_on_the_same_batch_serialize_instead_of_double_spending(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->withQtyCartons(10)->create();

        config(['database.connections.fefo_load_test_second' => config('database.connections.'.config('database.default'))]);
        $second = DB::connection('fefo_load_test_second');

        DB::beginTransaction();
        DB::table('inventory_batches')->where('id', $batch->id)->lockForUpdate()->first();

        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $second->beginTransaction();

        $blocked = false;

        try {
            $second->table('inventory_batches')->where('id', $batch->id)->lockForUpdate()->first();
        } catch (QueryException) {
            $blocked = true;
        } finally {
            $second->rollBack();
        }

        DB::rollBack();
        DB::purge('fefo_load_test_second');

        $this->assertTrue(
            $blocked,
            'A second connection was able to lock a batch row already locked by an in-progress deduction — concurrent orders could double-spend the same stock.'
        );
    }

    /**
     * @return array{0: Tenant, 1: Product, 2: Warehouse, 3: array<int, string>}
     */
    private function seedRealisticVolume(int $deepChainBatches = 0): array
    {
        $tenant = Tenant::factory()->create();
        $warehouses = Warehouse::factory()->recycle($tenant)->count(self::WAREHOUSES)->create();
        $noiseProducts = Product::factory()->recycle($tenant)->count(self::NOISE_PRODUCTS)->create();
        $targetProduct = Product::factory()->recycle($tenant)->create();
        $targetWarehouse = $warehouses->first();
        $now = now();

        $rows = [];

        foreach ($noiseProducts as $product) {
            foreach ($warehouses as $warehouse) {
                for ($i = 0; $i < self::BATCHES_PER_PAIR; $i++) {
                    $rows[] = $this->batchRow($tenant->id, $product->id, $warehouse->id, $now->copy()->addDays(60 + $i * 15), $now->copy()->subDays(120 - $i), 50);
                }
            }
        }

        $deepBatchIds = [];

        if ($deepChainBatches > 0) {
            // Isolated to only this pair, in strict expiry order — mixing
            // these with the "noise" grid above (which also touches this
            // exact product+warehouse pair) would make draw order
            // non-deterministic for the assertions in the deep-chain test.
            for ($i = 0; $i < $deepChainBatches; $i++) {
                $id = (string) Str::ulid();
                $deepBatchIds[] = $id;
                $rows[] = $this->batchRow($tenant->id, $targetProduct->id, $targetWarehouse->id, $now->copy()->addDays(10 + $i), $now->copy()->subDays(200 - $i), 10, $id);
            }
        } else {
            for ($i = 0; $i < self::BATCHES_PER_PAIR; $i++) {
                $rows[] = $this->batchRow($tenant->id, $targetProduct->id, $targetWarehouse->id, $now->copy()->addDays(60 + $i * 15), $now->copy()->subDays(120 - $i), 50);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('inventory_batches')->insert($chunk);
        }

        return [$tenant, $targetProduct, $targetWarehouse, $deepBatchIds];
    }

    private function batchRow(string $tenantId, string $productId, string $warehouseId, Carbon $expDate, Carbon $rcvDate, int $qty, ?string $id = null): array
    {
        $now = now();

        return [
            'id' => $id ?? (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'batch_no' => (string) Str::ulid(),
            'mfg_date' => $rcvDate->copy()->subDays(30)->toDateString(),
            'exp_date' => $expDate->toDateString(),
            'rcv_date' => $rcvDate->toDateString(),
            'qty_cartons' => $qty,
            'qty_packs' => 0,
            'cost_per_carton' => 100,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function service(): FefoStockService
    {
        return $this->app->make(FefoStockService::class);
    }
}

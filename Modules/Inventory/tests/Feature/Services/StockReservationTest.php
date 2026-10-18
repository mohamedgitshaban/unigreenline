<?php

namespace Modules\Inventory\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\FefoStockService;
use Modules\Inventory\Services\StockReservationService;
use Modules\Purchasing\Models\Supplier;
use Modules\Recap\Models\Recap;
use Modules\Sales\Services\ProcessReturnService;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * Stock set aside for a document (a manufacturing order) cannot be taken by anything else.
 */
class StockReservationTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->product = Product::factory()->recycle($this->tenant)->create(['carton_qty' => 10]);
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->ensureLedgerAccounts($this->tenant);
    }

    public function test_reservations_take_the_earliest_expiring_unexpired_batches_first(): void
    {
        $expired = $this->batch(10, '-1 day');
        $soon = $this->batch(4, '+30 days');
        $later = $this->batch(10, '+90 days');

        $reserved = $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 7, 'production_order', 'MO1', 'line-1');

        $this->assertSame(7, $reserved);
        $this->assertSame(0, $expired->fresh()->reserved_cartons);
        $this->assertSame(4, $soon->fresh()->reserved_cartons);
        $this->assertSame(3, $later->fresh()->reserved_cartons);
        $this->assertSame(2, StockReservation::query()->active()->count());
        $this->assertSame(7, $this->service()->reservedFor('production_order', 'MO1'));
    }

    public function test_it_reserves_only_what_is_free_and_reports_how_much(): void
    {
        $batch = $this->batch(10, '+30 days');

        $this->assertSame(6, $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 6, 'production_order', 'MO1'));
        // 4 are left: the second document gets 4 of the 8 it asked for.
        $this->assertSame(4, $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 8, 'production_order', 'MO2'));
        $this->assertSame(0, $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 1, 'production_order', 'MO3'));

        $this->assertSame(10, $batch->fresh()->reserved_cartons);
        $this->assertSame(0, $this->service()->freeCartons($this->product->id, $this->warehouse->id));
    }

    public function test_asking_again_for_the_same_line_adds_to_one_reservation(): void
    {
        $this->batch(10, '+30 days');

        $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 2, 'production_order', 'MO1', 'line-1');
        $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 3, 'production_order', 'MO1', 'line-1');

        $this->assertSame(1, StockReservation::query()->active()->count());
        $this->assertEquals(5, StockReservation::query()->active()->sum('qty_cartons'));
    }

    public function test_releasing_gives_the_cartons_back_all_or_in_part(): void
    {
        $batch = $this->batch(10, '+30 days');
        $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 8, 'production_order', 'MO1', 'line-1');

        $this->assertSame(3, $this->service()->release('production_order', 'MO1', 'line-1', 3));
        $this->assertSame(5, $batch->fresh()->reserved_cartons);
        $this->assertSame(5, $this->service()->reservedFor('production_order', 'MO1'));

        $this->assertSame(5, $this->service()->release('production_order', 'MO1'));
        $this->assertSame(0, $batch->fresh()->reserved_cartons);
        $this->assertSame(0, StockReservation::query()->active()->count());
        $this->assertSame(0, $this->service()->release('production_order', 'MO1'));   // nothing left to release
    }

    public function test_consuming_withdraws_reserved_stock_earliest_expiry_first(): void
    {
        $soon = $this->batch(4, '+30 days');
        $later = $this->batch(10, '+90 days');
        $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 8, 'production_order', 'MO1', 'line-1');

        $taken = $this->service()->consume('production_order', 'MO1', 'line-1', 6);

        $this->assertSame([4, 2], array_column($taken, 'cartons_taken'));
        $this->assertSame(0, $soon->fresh()->qty_cartons);
        $this->assertSame(0, $soon->fresh()->reserved_cartons);
        $this->assertSame(8, $later->fresh()->qty_cartons);
        $this->assertSame(2, $later->fresh()->reserved_cartons);
        $this->assertSame(2, $this->service()->reservedFor('production_order', 'MO1'));
        $this->assertSame('consumed', StockReservation::query()->where('batch_id', $soon->id)->value('status'));
    }

    public function test_sales_deduction_cannot_touch_reserved_cartons(): void
    {
        $batch = $this->batch(10, '+30 days');
        $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 7, 'production_order', 'MO1');
        $line = ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id];

        $fefo = app(FefoStockService::class);
        $this->assertSame([], $fefo->checkAvailability([$line + ['cartons_needed' => 3]]));
        $this->assertSame(3, $fefo->checkAvailability([$line + ['cartons_needed' => 4]])[0]['available']);

        $fefo->deduct([$line + ['cartons_needed' => 3]]);
        $this->assertSame(7, $batch->fresh()->qty_cartons);

        $this->expectException(InsufficientStockException::class);
        $fefo->deduct([$line + ['cartons_needed' => 1]]);
    }

    public function test_a_stock_write_off_cannot_take_reserved_cartons(): void
    {
        $this->actingAsRole('Warehouse Manager');
        $batch = $this->batch(10, '-3 days');   // expired: exactly what a write-off is for
        InventoryBatch::query()->whereKey($batch->id)->update(['reserved_cartons' => 4]);   // (reserved before it expired)
        $recap = Recap::factory()->recycle($this->tenant)->create(['warehouse_id' => $this->warehouse->id]);
        $recap->lines()->create(['batch_id' => $batch->id, 'product_id' => $this->product->id, 'batch_no' => $batch->batch_no, 'exp_date' => $batch->exp_date, 'qty_cartons' => 8, 'cost_per_carton' => 10, 'line_value' => 80]);

        $this->postJson("/api/v1/recaps/{$recap->id}/approve")->assertUnprocessable();
        $this->assertSame(10, $batch->fresh()->qty_cartons);

        // Writing off no more than the free cartons works.
        $recap->lines()->update(['qty_cartons' => 6]);
        $this->postJson("/api/v1/recaps/{$recap->id}/approve")->assertOk();
        $this->assertSame(4, $batch->fresh()->qty_cartons);
    }

    public function test_a_purchase_return_cannot_send_back_reserved_cartons(): void
    {
        $batch = $this->batch(10, '+30 days');
        $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 8, 'production_order', 'MO1');
        $supplier = Supplier::factory()->recycle($this->tenant)->create();

        $data = ['tenant_id' => $this->tenant->id, 'type' => 'Purchase Return', 'supplier_id' => $supplier->id, 'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'batch_no' => $batch->batch_no, 'unit' => 'Carton', 'amount' => 10];

        try {
            app(ProcessReturnService::class)->process($data + ['qty' => 3]);
            $this->fail('Sending back reserved cartons should have been refused.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(2, $e->shortfalls[0]['available']);
        }

        app(ProcessReturnService::class)->process($data + ['qty' => 2]);
        $this->assertSame(8, $batch->fresh()->qty_cartons);
    }

    public function test_the_availability_endpoint_shows_reserved_cartons_and_counts_only_the_free_ones(): void
    {
        $this->actingAsRole('Warehouse Manager');
        $this->batch(10, '+30 days');
        $this->service()->reserve($this->tenant->id, $this->product->id, $this->warehouse->id, 4, 'production_order', 'MO1');

        $this->getJson("/api/v1/products/{$this->product->id}/availability")->assertOk()
            ->assertJsonPath('data.total_available_cartons', 6)
            ->assertJsonPath('data.warehouses.0.available_cartons', 6)
            ->assertJsonPath('data.warehouses.0.reserved_cartons', 4);
    }

    private function batch(int $qty, string $expires): InventoryBatch
    {
        return InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->warehouse])->create([
            'qty_cartons' => $qty, 'exp_date' => now()->modify($expires)->toDateString(), 'cost_per_carton' => 10,
        ]);
    }

    private function service(): StockReservationService
    {
        return app(StockReservationService::class);
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

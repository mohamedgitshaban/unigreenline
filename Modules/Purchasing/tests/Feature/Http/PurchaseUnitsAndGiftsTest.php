<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\ProductUnitService;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class PurchaseUnitsAndGiftsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    private Unit $packet;

    private Unit $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->supplier = Supplier::factory()->recycle($this->tenant)->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->product = Product::factory()->recycle($this->tenant)->create(['tax_pct' => 0]);
        $this->packet = Unit::factory()->recycle($this->tenant)->create(['name' => 'Packet']);
        $this->package = Unit::factory()->recycle($this->tenant)->create(['name' => 'Package']);
        $carton = Unit::factory()->recycle($this->tenant)->create(['name' => 'Carton']);
        app(ProductUnitService::class)->sync($this->product, [
            ['unit_id' => $this->packet->id],
            ['unit_id' => $this->package->id, 'contains' => 10],
            ['unit_id' => $carton->id, 'contains' => 3],
        ]);
        Account::factory()->recycle($this->tenant)->create(['code' => '1300', 'type' => 'Asset']);
        Account::factory()->recycle($this->tenant)->create(['code' => '2100', 'type' => 'Liability']);
    }

    public function test_a_line_ordered_in_packages_is_converted_to_whole_cartons(): void
    {
        $this->actingAsRole('Administrator');

        // 6 packages × 10 packets = 60 packets = 2 cartons; 6 × 50 = 300 total → 150 per carton.
        $this->postJson('/api/v1/purchase-orders', $this->orderPayload([
            ['product_id' => $this->product->id, 'unit_id' => $this->package->id, 'qty' => 6, 'cost_per_unit' => 50],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.total', '300.00');

        $this->assertDatabaseHas('purchase_order_lines', [
            'product_id' => $this->product->id, 'unit_id' => $this->package->id, 'unit_qty' => 6,
            'qty_cartons' => 2, 'cost_per_carton' => '150.00', 'total' => '300.00',
        ]);
    }

    public function test_a_quantity_that_is_not_a_whole_number_of_cartons_is_rejected(): void
    {
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/purchase-orders', $this->orderPayload([
            ['product_id' => $this->product->id, 'unit_id' => $this->packet->id, 'qty' => 45, 'cost_per_unit' => 5],
        ]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.qty');

        $this->postJson('/api/v1/purchase-orders', $this->orderPayload([
            ['product_id' => $this->product->id, 'unit_id' => Unit::factory()->recycle($this->tenant)->create()->id, 'qty' => 30, 'cost_per_unit' => 5],
        ]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_id');

        $this->postJson('/api/v1/purchase-orders', $this->orderPayload([
            ['product_id' => $this->product->id, 'unit_id' => $this->packet->id, 'qty' => 60, 'cost_per_unit' => 5],
        ]))->assertCreated();
        $this->assertDatabaseHas('purchase_order_lines', ['qty_cartons' => 2, 'cost_per_carton' => '150.00']);
    }

    public function test_cartons_still_work_as_before(): void
    {
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/purchase-orders', $this->orderPayload([
            ['product_id' => $this->product->id, 'qty_cartons' => 3, 'cost_per_carton' => 100],
        ]))->assertCreated()->assertJsonPath('data.total', '300.00');
    }

    public function test_receiving_adds_free_gifts_to_stock_at_zero_cost_without_changing_the_payable(): void
    {
        $this->actingAsRole('Purchasing');
        $po = PurchaseOrder::factory()->recycle([$this->tenant, $this->supplier, $this->warehouse])->create(['subtotal' => 100, 'tax_amount' => 0, 'total' => 100]);
        $line = $po->lines()->create(['product_id' => $this->product->id, 'qty_cartons' => 1, 'cost_per_carton' => 100, 'total' => 100]);
        $giftProduct = Product::factory()->recycle($this->tenant)->create();

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'receipts' => [['line_id' => $line->id, 'batch_no' => 'PAID-1', 'exp_date' => now()->addYear()->toDateString()]],
            'free_gifts' => [
                ['product_id' => $this->product->id, 'unit_id' => $this->package->id, 'qty' => 3, 'batch_no' => 'GIFT-1', 'exp_date' => now()->addYear()->toDateString()],
                ['product_id' => $giftProduct->id, 'qty' => 4, 'batch_no' => 'GIFT-2', 'exp_date' => now()->addYear()->toDateString()],
            ],
        ])->assertOk();

        $paid = InventoryBatch::query()->where('batch_no', 'PAID-1')->firstOrFail();
        $this->assertFalse($paid->is_free_gift);

        $gift = InventoryBatch::query()->where('batch_no', 'GIFT-1')->firstOrFail();
        $this->assertTrue($gift->is_free_gift);
        $this->assertSame(1, $gift->qty_cartons);
        $this->assertSame('0.00', $gift->cost_per_carton);
        $this->assertSame($po->id, $gift->source_po_id);
        $this->assertSame(4, InventoryBatch::query()->where('batch_no', 'GIFT-2')->value('qty_cartons'));

        $this->assertSame('100.00', Account::query()->where('code', '1300')->first()->balance);
        $this->assertSame('100.00', Account::query()->where('code', '2100')->first()->balance);
    }

    public function test_a_gift_cannot_share_a_batch_number_with_paid_stock_and_a_bad_gift_rolls_everything_back(): void
    {
        $this->actingAsRole('Purchasing');
        $po = PurchaseOrder::factory()->recycle([$this->tenant, $this->supplier, $this->warehouse])->create(['subtotal' => 100, 'tax_amount' => 0, 'total' => 100]);
        $line = $po->lines()->create(['product_id' => $this->product->id, 'qty_cartons' => 1, 'cost_per_carton' => 100, 'total' => 100]);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'receipts' => [['line_id' => $line->id, 'batch_no' => 'SAME', 'exp_date' => now()->addYear()->toDateString()]],
            'free_gifts' => [['product_id' => $this->product->id, 'qty' => 1, 'batch_no' => 'SAME', 'exp_date' => now()->addYear()->toDateString()]],
        ])->assertUnprocessable();

        $this->assertSame(0, InventoryBatch::query()->count());
        $this->assertSame('draft', $po->fresh()->status);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function orderPayload(array $lines): array
    {
        return ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'lines' => $lines];
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}

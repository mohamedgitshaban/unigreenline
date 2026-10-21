<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\ProductUnitService;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

class SalesOrderPricingAndGiftsTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Customer $customer;

    private Product $product;

    private Unit $packet;

    private Unit $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->customer = Customer::factory()->recycle($this->tenant)->create(['credit_limit' => 1000000]);
        // Packet 1 → Package 10 → Carton 30; sells at 10 a packet, never below 8.
        $this->product = Product::factory()->recycle($this->tenant)->create(['tax_pct' => 0, 'pack_selling_price' => 10, 'min_selling_price' => 8]);
        $this->packet = Unit::factory()->recycle($this->tenant)->create(['name' => 'Packet']);
        $this->package = Unit::factory()->recycle($this->tenant)->create(['name' => 'Package']);
        $carton = Unit::factory()->recycle($this->tenant)->create(['name' => 'Carton']);
        app(ProductUnitService::class)->sync($this->product, [
            ['unit_id' => $this->packet->id],
            ['unit_id' => $this->package->id, 'contains' => 10],
            ['unit_id' => $carton->id, 'contains' => 3],
        ]);
        foreach (['1200' => 'Asset', '4100' => 'Revenue', '2300' => 'Liability'] as $code => $type) {
            Account::factory()->recycle($this->tenant)->create(['code' => $code, 'type' => $type]);
        }
        $this->ensureLedgerAccounts($this->tenant);
        $this->actingAsRole('Sales Rep');
    }

    public function test_a_price_below_the_minimum_selling_price_is_rejected_but_the_minimum_itself_is_fine(): void
    {
        $this->stock(10);

        // Per package the floor is 8 × 10 = 80.
        $this->order([['unit_id' => $this->package->id, 'qty' => 1, 'unit_price' => 79.99]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
        $this->order([['unit_id' => $this->package->id, 'qty' => 1, 'unit_price' => 80]])->assertCreated();
    }

    public function test_the_discount_counts_towards_the_minimum(): void
    {
        $this->stock(10);

        $this->order([['unit' => 'Pack', 'qty' => 5, 'unit_price' => 10, 'discount_pct' => 25]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
        $this->order([['unit' => 'Pack', 'qty' => 5, 'unit_price' => 10, 'discount_pct' => 20]])->assertCreated();
    }

    public function test_a_product_without_a_minimum_has_no_floor(): void
    {
        $this->stock(10);
        $this->product->update(['min_selling_price' => null]);

        $this->order([['unit' => 'Pack', 'qty' => 5, 'unit_price' => 0.5]])->assertCreated();
    }

    public function test_a_line_in_packages_draws_the_right_number_of_cartons(): void
    {
        $batch = $this->stock(5);

        // 7 packages = 70 packets → 3 cartons of 30 (rounded up).
        $id = $this->order([['unit_id' => $this->package->id, 'qty' => 7, 'unit_price' => 100]])
            ->assertCreated()
            ->assertJsonPath('data.lines_count', 1)
            ->json('data.id');

        $this->putJson("/api/v1/sales-orders/{$id}/status", ['status' => 'invoiced'])->assertOk();
        $this->assertSame(2, $batch->fresh()->qty_cartons);
    }

    public function test_gift_lines_are_free_and_only_draw_from_free_gift_stock(): void
    {
        $paid = $this->stock(5);
        $gift = $this->stock(2, ['is_free_gift' => true, 'cost_per_carton' => 0]);

        $id = $this->order([
            ['unit' => 'Carton', 'qty' => 2, 'unit_price' => 300],
            ['unit' => 'Carton', 'qty' => 2, 'is_gift' => true],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total', '600.00')
            ->json('data.id');

        $this->assertDatabaseHas('sales_order_lines', ['so_id' => $id, 'is_gift' => 1, 'unit_price' => '0.00', 'subtotal' => '0.00']);

        $this->putJson("/api/v1/sales-orders/{$id}/status", ['status' => 'invoiced'])->assertOk();

        $this->assertSame(3, $paid->fresh()->qty_cartons);
        $this->assertSame(0, $gift->fresh()->qty_cartons);
    }

    public function test_gift_stock_is_not_sold_on_ordinary_lines_and_cannot_be_over_gifted(): void
    {
        $this->stock(0);
        $this->stock(3, ['is_free_gift' => true, 'cost_per_carton' => 0]);

        $this->order([['unit' => 'Carton', 'qty' => 1, 'unit_price' => 300]])->assertUnprocessable()->assertJsonStructure(['shortfalls']);
        $this->order([['unit' => 'Carton', 'qty' => 4, 'is_gift' => true]])->assertUnprocessable()->assertJsonStructure(['shortfalls']);
        $this->order([['unit' => 'Carton', 'qty' => 3, 'is_gift' => true]])->assertCreated();
    }

    public function test_a_non_gift_line_needs_a_price(): void
    {
        $this->stock(5);

        $this->order([['unit' => 'Carton', 'qty' => 1]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function order(array $lines): TestResponse
    {
        $lines = array_map(fn (array $line) => ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id] + $line, $lines);

        return $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $this->customer->id,
            'pay_type' => 'cash',
            'lines' => $lines,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stock(int $cartons, array $attributes = []): InventoryBatch
    {
        return InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->warehouse])->withQtyCartons($cartons)->create($attributes);
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

<?php

namespace Modules\Inventory\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Database\Seeders\ProductUnitSeeder;
use Modules\Inventory\Database\Seeders\UnitSeeder;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Modules\Inventory\Models\ProductUnit;
use Modules\Inventory\Models\Unit;
use Tests\TestCase;

class UnitsAndPackagingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Unit $packet;

    private Unit $package;

    private Unit $carton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->packet = Unit::factory()->recycle($this->tenant)->create(['name' => 'Packet']);
        $this->package = Unit::factory()->recycle($this->tenant)->create(['name' => 'Package']);
        $this->carton = Unit::factory()->recycle($this->tenant)->create(['name' => 'Carton']);
    }

    public function test_admin_manages_unit_names(): void
    {
        $this->actingAsRole('Administrator');

        $id = $this->postJson('/api/v1/units', ['name' => 'Pallet'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/units', ['name' => 'Pallet'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("/api/v1/units/{$id}", ['name' => 'Big pallet', 'active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->getJson('/api/v1/units')->assertOk()->assertJsonCount(4, 'data');
        $this->deleteJson("/api/v1/units/{$id}")->assertNoContent();
    }

    public function test_a_unit_in_use_cannot_be_deleted_and_changes_need_permission(): void
    {
        $this->actingAsRole('Administrator');
        $this->postJson('/api/v1/products', $this->productPayload())->assertCreated();
        $this->deleteJson("/api/v1/units/{$this->packet->id}")->assertConflict();

        $this->actingAsRole('Sales Rep');
        $this->getJson('/api/v1/units')->assertOk();
        $this->postJson('/api/v1/units', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_a_product_is_packaged_packet_to_package_to_carton_and_stored_by_the_carton(): void
    {
        $this->actingAsRole('Administrator');

        // 10 packets per package, 3 packages per carton = 30 packets per carton.
        $this->postJson('/api/v1/products', $this->productPayload())
            ->assertCreated()
            ->assertJsonPath('data.carton_qty', 30)
            ->assertJsonPath('data.pack_unit', 'Packet')
            ->assertJsonPath('data.units.0.name', 'Packet')
            ->assertJsonPath('data.units.0.base_qty', 1)
            ->assertJsonPath('data.units.1.name', 'Package')
            ->assertJsonPath('data.units.1.base_qty', 10)
            ->assertJsonPath('data.units.2.name', 'Carton')
            ->assertJsonPath('data.units.2.base_qty', 30);
    }

    public function test_the_packaging_ladder_can_be_changed_and_must_be_well_formed(): void
    {
        $this->actingAsRole('Administrator');
        $id = $this->postJson('/api/v1/products', $this->productPayload())->json('data.id');

        $this->putJson("/api/v1/products/{$id}", ['units' => [['unit_id' => $this->packet->id], ['unit_id' => $this->carton->id, 'contains' => 12]]])
            ->assertOk()
            ->assertJsonPath('data.carton_qty', 12)
            ->assertJsonCount(2, 'data.units');

        $this->putJson("/api/v1/products/{$id}", ['units' => [['unit_id' => $this->packet->id], ['unit_id' => $this->carton->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('units.1.contains');
        $this->putJson("/api/v1/products/{$id}", ['units' => [['unit_id' => $this->packet->id, 'contains' => 4]]])
            ->assertUnprocessable()->assertJsonValidationErrors('units.0.contains');
        $this->putJson("/api/v1/products/{$id}", ['units' => [['unit_id' => $this->packet->id], ['unit_id' => $this->packet->id, 'contains' => 2]]])
            ->assertUnprocessable();
    }

    public function test_availability_reports_stock_in_base_units_too(): void
    {
        $this->actingAsRole('Administrator');
        $id = $this->postJson('/api/v1/products', $this->productPayload())->json('data.id');
        InventoryBatch::factory()->recycle($this->tenant)->withQtyCartons(2)->create(['product_id' => $id]);

        $this->getJson("/api/v1/products/{$id}/availability")
            ->assertJsonPath('data.total_available_cartons', 2)
            ->assertJsonPath('data.total_available_base_units', 60);
    }

    public function test_minimum_selling_price_is_shown_to_everyone_but_cost_only_to_administrators(): void
    {
        $this->actingAsRole('Administrator');
        $id = $this->postJson('/api/v1/products', $this->productPayload(['min_selling_price' => 8, 'pack_cost_price' => 5, 'pack_selling_price' => 10]))
            ->assertCreated()
            ->assertJsonPath('data.min_selling_price', '8.00')
            ->assertJsonPath('data.min_selling_price_per_carton', '240.00')
            ->assertJsonPath('data.pack_cost_price', '5.00')
            ->json('data.id');

        $this->actingAsRole('Sales Manager');
        $this->actingAsRole('Warehouse Manager');
        $response = $this->getJson("/api/v1/products/{$id}")->assertOk();
        $response->assertJsonPath('data.min_selling_price', '8.00');
        $response->assertJsonMissingPath('data.pack_cost_price');
        $response->assertJsonMissingPath('data.cost_price');
    }

    public function test_minimum_selling_price_cannot_exceed_the_selling_price(): void
    {
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/products', $this->productPayload(['min_selling_price' => 20, 'pack_selling_price' => 10]))
            ->assertUnprocessable()->assertJsonValidationErrors('min_selling_price');

        $id = $this->postJson('/api/v1/products', $this->productPayload(['min_selling_price' => 5, 'pack_selling_price' => 10]))->json('data.id');
        $this->putJson("/api/v1/products/{$id}", ['min_selling_price' => 11])->assertUnprocessable();
    }

    public function test_seeders_add_the_standard_units_and_give_existing_products_a_carton_ladder(): void
    {
        Unit::query()->delete();
        $product = Product::factory()->recycle($this->tenant)->create(['carton_qty' => 24]);
        Product::factory()->recycle($this->tenant)->create(['carton_qty' => 1]);

        $this->seed(UnitSeeder::class);
        $this->seed(ProductUnitSeeder::class);
        $this->seed(UnitSeeder::class);
        $this->seed(ProductUnitSeeder::class);

        $this->assertSame(['Carton', 'Package', 'Packet'], Unit::query()->orderBy('name')->pluck('name')->all());
        $levels = $product->units()->with('unit')->get();
        $this->assertSame(['Packet', 'Carton'], $levels->pluck('unit.name')->all());
        $this->assertSame([1, 24], $levels->pluck('base_qty')->all());
        $this->assertSame(2, ProductUnit::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return $overrides + [
            'category_id' => ProductCategory::factory()->recycle($this->tenant)->create()->id,
            'name' => 'Product X '.fake()->unique()->numerify('###'),
            'sku' => fake()->unique()->bothify('SKU-####'),
            'pack_cost_price' => 5,
            'pack_selling_price' => 10,
            'units' => [
                ['unit_id' => $this->packet->id],
                ['unit_id' => $this->package->id, 'contains' => 10],
                ['unit_id' => $this->carton->id, 'contains' => 3],
            ],
        ];
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}

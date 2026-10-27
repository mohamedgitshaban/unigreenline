<?php

namespace Modules\Core\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

/**
 * The pick-lists behind a form's dropdowns (`->canOrLookup()`): a signed-in user without the
 * list's permission gets `id` and `name` only, instead of a 403, so a Sales Rep can fill in a sales order.
 */
class LookupListsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_unauthenticated_request_for_products_returns_401(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_sales_rep_without_products_view_gets_only_id_and_name(): void
    {
        $user = $this->actingAsRole('Sales Rep');
        $product = Product::factory()->recycle($this->tenant)->create(['name' => 'Ivermectin']);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.name', 'Ivermectin');
        $this->assertFalse($user->can('products.view'));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0')));
    }

    public function test_user_with_products_view_still_gets_the_full_product(): void
    {
        $this->actingAsRole('Warehouse Manager');
        Product::factory()->recycle($this->tenant)->create(['sku' => 'IVM-001']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'IVM-001');
    }

    public function test_product_lookup_lists_only_the_users_own_tenant_ordered_by_name(): void
    {
        $this->actingAsRole('Sales Rep');
        Product::factory()->recycle($this->tenant)->create(['name' => 'Oxytetracycline']);
        Product::factory()->recycle($this->tenant)->create(['name' => 'Ivermectin']);
        Product::factory()->create(['name' => 'Other tenant product']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Ivermectin', 'Oxytetracycline']);
    }

    public function test_product_lookup_searches_the_name_and_treats_wildcards_literally(): void
    {
        $this->actingAsRole('Sales Rep');
        Product::factory()->recycle($this->tenant)->create(['name' => 'Oxytetracycline']);
        Product::factory()->recycle($this->tenant)->create(['name' => '100% Pure']);

        $this->getJson('/api/v1/products?search=oxy')->assertOk()->assertJsonPath('data.*.name', ['Oxytetracycline']);
        $this->getJson('/api/v1/products?search=%25')->assertOk()->assertJsonPath('data.*.name', ['100% Pure']);
    }

    public function test_product_lookup_ignores_filters_on_columns_the_user_cannot_see(): void
    {
        $this->actingAsRole('Sales Rep');
        Product::factory()->recycle($this->tenant)->count(2)->create();

        $this->getJson('/api/v1/products?filter[pack_cost_price][gt]=999999&sort_by=pack_cost_price')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_product_lookup_does_not_export(): void
    {
        $this->actingAsRole('Sales Rep');
        Product::factory()->recycle($this->tenant)->create();

        $this->getJson('/api/v1/products?export=csv')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name']]]);
    }

    public function test_a_single_product_is_still_forbidden_without_products_view(): void
    {
        $this->actingAsRole('Sales Rep');
        $product = Product::factory()->recycle($this->tenant)->create();

        $this->getJson("/api/v1/products/{$product->id}")->assertForbidden();
    }

    public function test_warehouse_lookup_names_every_warehouse_of_the_company_even_when_none_is_assigned(): void
    {
        $this->actingAsRole('Sales Rep');
        Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Cairo Main']);
        Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Alex Branch']);
        Warehouse::factory()->create(['name' => 'Other tenant warehouse']);

        $response = $this->getJson('/api/v1/warehouses');

        $response->assertOk()->assertJsonPath('data.*.name', ['Alex Branch', 'Cairo Main']);
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0')));
    }

    public function test_warehouse_manager_with_warehouses_view_still_sees_only_assigned_warehouses(): void
    {
        $user = $this->actingAsRole('Warehouse Manager');
        $assigned = Warehouse::factory()->recycle($this->tenant)->create();
        Warehouse::factory()->recycle($this->tenant)->create();
        $assigned->users()->attach($user);

        $this->getJson('/api/v1/warehouses')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$assigned->id])
            ->assertJsonPath('data.0.city', $assigned->city);
    }

    public function test_unit_lookup_returns_only_id_and_name_without_units_view(): void
    {
        $user = $this->actingAsRole('Customer Service');
        Unit::factory()->recycle($this->tenant)->create(['name' => 'Packet']);

        $response = $this->getJson('/api/v1/units');

        $response->assertOk()->assertJsonPath('data.*.name', ['Packet']);
        $this->assertFalse($user->can('units.view'));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0')));
    }
}

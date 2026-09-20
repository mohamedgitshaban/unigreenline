<?php

namespace Modules\Inventory\Tests\Feature\Http\Products;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Tests\TestCase;

class ProductStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_product_and_returns_computed_prices(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $category = ProductCategory::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/products', [
            'category_id' => $category->id,
            'name' => 'Oxytetracycline 20% Injectable',
            'sku' => 'PRD-00001',
            'pack_unit' => 'Vial 100ml',
            'carton_qty' => 20,
            'pack_cost_price' => 10,
            'pack_selling_price' => 15,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.cost_price', '200.00');
        $response->assertJsonPath('data.selling_price', '300.00');
        // Regression: tax_pct has a DB default (14) and wasn't submitted —
        // the response must reflect it immediately, not serialize it as null.
        $response->assertJsonPath('data.tax_pct', '14.00');
        $response->assertJsonPath('data.active', true);
    }

    public function test_duplicate_sku_within_the_same_tenant_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $category = ProductCategory::factory()->recycle($tenant)->create();
        Product::factory()->recycle([$tenant, $category])->create(['sku' => 'PRD-00001']);

        $response = $this->postJson('/api/v1/products', [
            'category_id' => $category->id,
            'name' => 'Another Product',
            'sku' => 'PRD-00001',
            'pack_unit' => 'Vial 100ml',
            'carton_qty' => 10,
            'pack_cost_price' => 5,
            'pack_selling_price' => 8,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['sku']);
    }

    public function test_unknown_category_id_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/products', [
            'category_id' => 'not-a-real-category',
            'name' => 'Product',
            'sku' => 'PRD-00002',
            'pack_unit' => 'Vial 100ml',
            'carton_qty' => 10,
            'pack_cost_price' => 5,
            'pack_selling_price' => 8,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['category_id']);
    }
}

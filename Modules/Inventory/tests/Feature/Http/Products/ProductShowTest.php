<?php

namespace Modules\Inventory\Tests\Feature\Http\Products;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class ProductShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_nested_category_and_batches_with_their_warehouse(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $category = ProductCategory::factory()->recycle($tenant)->create(['name' => 'Antibiotics']);
        $warehouse = Warehouse::factory()->recycle($tenant)->create(['name' => 'Cairo Main']);
        $product = Product::factory()->recycle($tenant)->for($category, 'category')->create();
        InventoryBatch::factory()->recycle($tenant)->for($product)->for($warehouse)->create();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.category.name', 'Antibiotics')
            ->assertJsonPath('data.batches.0.warehouse.name', 'Cairo Main');
    }
}

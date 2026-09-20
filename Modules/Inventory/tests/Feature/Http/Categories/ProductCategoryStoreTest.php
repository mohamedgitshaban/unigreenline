<?php

namespace Modules\Inventory\Tests\Feature\Http\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\ProductCategory;
use Tests\TestCase;

class ProductCategoryStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_category(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/categories', ['name' => 'Antibiotics']);

        $response->assertCreated();
        $this->assertDatabaseHas('product_categories', ['name' => 'Antibiotics', 'tenant_id' => $tenant->id]);
    }

    public function test_duplicate_name_within_the_same_tenant_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        ProductCategory::factory()->recycle($tenant)->create(['name' => 'Antibiotics']);

        $response = $this->postJson('/api/v1/categories', ['name' => 'Antibiotics']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }
}

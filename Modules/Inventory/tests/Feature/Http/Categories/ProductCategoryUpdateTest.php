<?php

namespace Modules\Inventory\Tests\Feature\Http\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\ProductCategory;
use Tests\TestCase;

class ProductCategoryUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_updates_a_category(): void
    {
        $this->actingAsRole('Warehouse Manager');
        $category = ProductCategory::factory()->recycle($this->tenant)->create(['name' => 'Antibiotics', 'active' => true]);

        $response = $this->putJson("/api/v1/categories/{$category->id}", ['name' => 'Antibiotics & Antimicrobials', 'active' => false]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Antibiotics & Antimicrobials');
        $this->assertDatabaseHas('product_categories', ['id' => $category->id, 'name' => 'Antibiotics & Antimicrobials', 'active' => false]);
    }

    public function test_keeping_its_own_name_is_not_a_duplicate(): void
    {
        $this->actingAsRole('Warehouse Manager');
        $category = ProductCategory::factory()->recycle($this->tenant)->create(['name' => 'Antibiotics']);

        $this->putJson("/api/v1/categories/{$category->id}", ['name' => 'Antibiotics', 'code' => 'ANT'])->assertOk();
    }

    public function test_renaming_to_another_categorys_name_is_rejected(): void
    {
        $this->actingAsRole('Warehouse Manager');
        ProductCategory::factory()->recycle($this->tenant)->create(['name' => 'Vaccines']);
        $category = ProductCategory::factory()->recycle($this->tenant)->create(['name' => 'Antibiotics']);

        $response = $this->putJson("/api/v1/categories/{$category->id}", ['name' => 'Vaccines']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_user_without_inventory_edit_is_forbidden(): void
    {
        $this->actingAsRole('Sales Rep');
        $category = ProductCategory::factory()->recycle($this->tenant)->create();

        $this->putJson("/api/v1/categories/{$category->id}", ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_category_from_another_tenant_is_forbidden(): void
    {
        $this->actingAsRole('Warehouse Manager');
        $otherCategory = ProductCategory::factory()->recycle(Tenant::factory()->create())->create(['name' => 'Antibiotics']);

        $this->putJson("/api/v1/categories/{$otherCategory->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->assertDatabaseHas('product_categories', ['id' => $otherCategory->id, 'name' => 'Antibiotics']);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);
    }
}

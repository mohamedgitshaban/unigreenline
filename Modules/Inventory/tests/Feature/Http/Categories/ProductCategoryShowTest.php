<?php

namespace Modules\Inventory\Tests\Feature\Http\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\ProductCategory;
use Tests\TestCase;

class ProductCategoryShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_employee_can_view_a_category(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Employee');
        Sanctum::actingAs($user);

        $record = ProductCategory::factory()->recycle($tenant)->create();

        $response = $this->getJson("/api/v1/categories/{$record->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $record->id);
    }

    public function test_a_category_from_another_tenant_is_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $record = ProductCategory::factory()->create();

        $response = $this->getJson("/api/v1/categories/{$record->id}");

        $response->assertNotFound();
    }
}

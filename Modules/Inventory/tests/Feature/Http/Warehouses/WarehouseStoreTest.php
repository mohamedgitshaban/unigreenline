<?php

namespace Modules\Inventory\Tests\Feature\Http\Warehouses;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class WarehouseStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_manager_can_create_a_warehouse(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/warehouses', [
            'name' => 'Main Warehouse',
            'city' => 'Cairo',
            'governorate' => 'Cairo',
            'temperature' => 'ambient',
            'capacity' => 10000,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('warehouses', [
            'name' => 'Main Warehouse',
            'tenant_id' => $tenant->id,
        ]);
        // Regression: status has a DB default and wasn't submitted — the
        // response must reflect it immediately, not serialize it as null.
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonPath('data.stock_value', '0.00');
    }

    public function test_manager_name_is_derived_from_manager_id_not_client_input(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $manager = User::factory()->recycle($tenant)->create(['name' => 'Khalid Omar']);

        $response = $this->postJson('/api/v1/warehouses', [
            'name' => 'Cold Storage',
            'city' => 'Cairo',
            'governorate' => 'Cairo',
            'manager_id' => $manager->id,
            'manager_name' => 'Someone Else', // must be ignored
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.manager_name', 'Khalid Omar');
    }

    public function test_sales_rep_cannot_create_a_warehouse(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/warehouses', [
            'name' => 'Main Warehouse',
            'city' => 'Cairo',
            'governorate' => 'Cairo',
        ]);

        $response->assertForbidden();
    }

    public function test_missing_required_fields_returns_422(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/warehouses', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name', 'city', 'governorate']);
    }
}

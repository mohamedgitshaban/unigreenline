<?php

namespace Modules\Inventory\Tests\Feature\Http\Warehouses;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class WarehouseIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/warehouses');

        $response->assertUnauthorized();
    }

    public function test_user_without_inventory_view_permission_is_forbidden(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Customer Service'); // has no inventory.* permissions
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/warehouses');

        $response->assertForbidden();
    }

    public function test_warehouse_manager_only_sees_their_assigned_warehouses(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::query()->where('slug', 'vetpharma')->first() ?? Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $assigned = Warehouse::factory()->recycle($tenant)->create(['name' => 'Assigned WH']);
        $unassigned = Warehouse::factory()->recycle($tenant)->create(['name' => 'Unassigned WH']);
        $assigned->users()->attach($user);

        $response = $this->getJson('/api/v1/warehouses');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($unassigned->id));
    }

    public function test_owner_sees_every_warehouse_regardless_of_assignment(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        Warehouse::factory()->recycle($tenant)->count(3)->create();

        $response = $this->getJson('/api/v1/warehouses');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    /**
     * Regression: Warehouse::visibleTo() only special-cased Owner/Auditor —
     * an Administrator with zero user_warehouses rows saw an empty list
     * despite spec §2 describing "full system access". Found via an
     * end-to-end Postman collection run against a real seeded DB.
     */
    public function test_administrator_sees_every_warehouse_regardless_of_assignment(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        Warehouse::factory()->recycle($tenant)->count(3)->create();

        $response = $this->getJson('/api/v1/warehouses');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_response_is_paginated(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        Warehouse::factory()->recycle($tenant)->count(3)->create();

        $response = $this->getJson('/api/v1/warehouses?per_page=2');

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', 2);
        $this->assertCount(2, $response->json('data'));
    }
}

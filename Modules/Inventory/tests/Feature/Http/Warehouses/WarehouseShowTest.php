<?php

namespace Modules\Inventory\Tests\Feature\Http\Warehouses;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class WarehouseShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_warehouse_with_its_batches(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $warehouse->users()->attach($user);
        $product = Product::factory()->recycle($tenant)->create();
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->create();

        $response = $this->getJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $warehouse->id);
        $response->assertJsonPath('data.batches.0.id', $batch->id);
    }

    public function test_returns_403_when_the_warehouse_is_not_assigned_to_the_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $warehouse = Warehouse::factory()->recycle($tenant)->create();

        $response = $this->getJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertForbidden();
    }
}

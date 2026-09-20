<?php

namespace Modules\Inventory\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class TransferStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_manager_transfers_stock_between_their_assigned_warehouses(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create();
        $from = Warehouse::factory()->recycle($tenant)->create();
        $to = Warehouse::factory()->recycle($tenant)->create();
        $from->users()->attach($user);
        $to->users()->attach($user);
        InventoryBatch::factory()->recycle([$tenant, $product, $from])->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 50]);

        $response = $this->postJson('/api/v1/transfers', [
            'product_id' => $product->id,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'batch_no' => 'BATCH-X',
            'qty_cartons' => 20,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'completed');
    }

    public function test_same_warehouse_for_both_sides_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/transfers', [
            'product_id' => $product->id,
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-X',
            'qty_cartons' => 20,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['from_warehouse_id']);
    }

    public function test_unassigned_destination_warehouse_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create();
        $from = Warehouse::factory()->recycle($tenant)->create();
        $to = Warehouse::factory()->recycle($tenant)->create(); // not assigned
        $from->users()->attach($user);

        $response = $this->postJson('/api/v1/transfers', [
            'product_id' => $product->id,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'batch_no' => 'BATCH-X',
            'qty_cartons' => 20,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['to_warehouse_id']);
    }
}

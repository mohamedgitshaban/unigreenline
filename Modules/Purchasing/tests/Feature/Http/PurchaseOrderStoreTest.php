<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class PurchaseOrderStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchasing_role_creates_a_purchase_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Purchasing');
        Sanctum::actingAs($user);

        $supplier = Supplier::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_cartons' => 10, 'cost_per_carton' => 90],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.created_by', $user->id);
    }

    public function test_warehouse_manager_cannot_create_a_purchase_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $supplier = Supplier::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_cartons' => 10, 'cost_per_carton' => 90],
            ],
        ]);

        $response->assertForbidden();
    }
}

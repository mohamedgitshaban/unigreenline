<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class ReturnStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_manager_records_a_sales_return(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/returns', [
            'type' => 'Damaged',
            'qty' => 3,
            'unit' => 'Carton',
            'amount' => 60,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'completed');
    }

    public function test_purchasing_role_records_a_purchase_return_without_sales_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Purchasing'); // has purchasing.* only, no sales.*
        Sanctum::actingAs($user);

        $supplier = Supplier::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->create(['batch_no' => 'BATCH-X', 'qty_cartons' => 10]);

        $response = $this->postJson('/api/v1/returns', [
            'type' => 'Purchase Return',
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-X',
            'qty' => 3,
            'unit' => 'Carton',
            'amount' => 60,
        ]);

        $response->assertCreated();
        $this->assertSame(7, InventoryBatch::query()->where('batch_no', 'BATCH-X')->value('qty_cartons'));
    }

    public function test_purchase_return_without_a_supplier_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Purchasing');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/returns', [
            'type' => 'Purchase Return',
            'qty' => 3,
            'unit' => 'Carton',
            'amount' => 60,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['supplier_id', 'product_id', 'warehouse_id', 'batch_no']);
    }

    public function test_customer_service_cannot_record_a_return(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/returns', [
            'type' => 'Damaged',
            'qty' => 3,
            'unit' => 'Carton',
            'amount' => 60,
        ]);

        $response->assertForbidden();
    }
}

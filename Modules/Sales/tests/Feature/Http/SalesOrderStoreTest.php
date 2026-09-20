<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class SalesOrderStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_creates_an_order_under_their_own_name(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $customer = Customer::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $warehouse->users()->attach($rep);
        $product = Product::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(50)->create();

        $response = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'pay_type' => 'cash',
            'lines' => [
                ['product_id' => $product->id, 'qty' => 5, 'unit' => 'Carton', 'unit_price' => 65],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sales_rep_id', $rep->id);
    }

    public function test_sales_rep_cannot_create_an_order_under_another_reps_name(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);
        $otherRep = User::factory()->recycle($tenant)->create();

        $customer = Customer::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $warehouse->users()->attach($rep);
        $product = Product::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $customer->id,
            'sales_rep_id' => $otherRep->id,
            'warehouse_id' => $warehouse->id,
            'pay_type' => 'cash',
            'lines' => [
                ['product_id' => $product->id, 'qty' => 5, 'unit' => 'Carton', 'unit_price' => 65],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['sales_rep_id']);
    }

    public function test_insufficient_stock_returns_422_with_shortfall_details(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $customer = Customer::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $warehouse->users()->attach($rep);
        $product = Product::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(2)->create();

        $response = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'pay_type' => 'cash',
            'lines' => [
                ['product_id' => $product->id, 'qty' => 5, 'unit' => 'Carton', 'unit_price' => 65],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('shortfalls.0.needed', 5);
        $response->assertJsonPath('shortfalls.0.available', 2);
    }

    /**
     * user_warehouses assignment (spec §2 layer 1) scopes Inventory-module
     * warehouse access, not who may sell out of a warehouse — a Sales Rep
     * or Manager never gets assigned to a warehouse at all, so requiring
     * that here would make order creation impossible for Sales roles.
     */
    public function test_sales_rep_can_create_an_order_against_a_warehouse_they_are_not_assigned_to(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $customer = Customer::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create(); // not assigned to $rep
        $product = Product::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(50)->create();

        $response = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'pay_type' => 'cash',
            'lines' => [
                ['product_id' => $product->id, 'qty' => 5, 'unit' => 'Carton', 'unit_price' => 65],
            ],
        ]);

        $response->assertCreated();
    }

    public function test_customer_service_role_cannot_create_a_sales_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'pay_type' => 'cash',
            'lines' => [
                ['product_id' => $product->id, 'qty' => 5, 'unit' => 'Carton', 'unit_price' => 65],
            ],
        ]);

        $response->assertForbidden();
    }
}

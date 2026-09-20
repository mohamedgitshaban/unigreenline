<?php

namespace Modules\Analytics\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class AnalyticsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_sees_the_dashboard(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/analytics/dashboard');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [
            'monthly_sales', 'active_customers', 'collected_amount', 'outstanding_ar',
            'overdue_amount', 'inventory_value', 'critical_expiry_count', 'pending_deliveries',
        ]]);
    }

    public function test_sales_manager_cannot_see_the_dashboard(): void
    {
        // Spec's own role table never grants analytics.* to an operational
        // role — only the oversight roles (Administrator/Owner/Auditor)
        // have it in this build.
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/analytics/dashboard');

        $response->assertForbidden();
    }

    public function test_expiry_endpoint_orders_by_soonest_expiry_first_and_computes_days_left(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->create(['exp_date' => now()->addDays(20)]);
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->create(['exp_date' => now()->addDays(5)]);

        $response = $this->getJson('/api/v1/analytics/expiry');

        $response->assertOk();
        $response->assertJsonPath('data.0.days_left', 5);
        $response->assertJsonPath('data.1.days_left', 20);
    }

    public function test_stock_endpoint_rolls_up_quantity_across_warehouses(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Auditor');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create();
        $warehouseA = Warehouse::factory()->recycle($tenant)->create();
        $warehouseB = Warehouse::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product])->for($warehouseA, 'warehouse')->create(['qty_cartons' => 30]);
        InventoryBatch::factory()->recycle([$tenant, $product])->for($warehouseB, 'warehouse')->create(['qty_cartons' => 20]);

        $response = $this->getJson('/api/v1/analytics/stock');

        $response->assertOk();
        $response->assertJsonPath('data.0.total_qty_cartons', 50);
        $response->assertJsonCount(2, 'data.0.warehouses');
    }
}

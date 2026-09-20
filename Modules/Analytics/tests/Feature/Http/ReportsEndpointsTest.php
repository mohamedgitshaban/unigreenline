<?php

namespace Modules\Analytics\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class ReportsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_export_the_sales_report_filtered_by_date(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create();
        SalesOrder::factory()->recycle([$tenant, $customer])->create(['order_date' => '2026-06-15']);
        SalesOrder::factory()->recycle([$tenant, $customer])->create(['order_date' => '2026-01-01']);

        $response = $this->getJson('/api/v1/reports/sales?start_date=2026-06-01&end_date=2026-06-30');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_sales_manager_without_export_permission_is_forbidden(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Manager'); // has sales.export, not analytics.export
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/sales');

        $response->assertForbidden();
    }

    public function test_auditor_can_export_the_inventory_report(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Auditor');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/inventory');

        $response->assertOk();
    }
}

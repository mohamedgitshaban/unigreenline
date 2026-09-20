<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class ArAgingReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_view_ar_aging_without_any_sales_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/ar-aging');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['as_of', 'customers', 'grand_total']]);
    }

    public function test_warehouse_manager_cannot_view_ar_aging(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/ar-aging');

        $response->assertForbidden();
    }
}

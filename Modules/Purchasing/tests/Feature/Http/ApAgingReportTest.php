<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class ApAgingReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_view_ap_aging_without_any_purchasing_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/ap-aging');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['as_of', 'suppliers', 'grand_total_owed']]);
    }

    public function test_sales_rep_cannot_view_ap_aging(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/ap-aging');

        $response->assertForbidden();
    }
}

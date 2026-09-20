<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class LeadStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_creates_a_lead(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $response = $this->postJson('/api/v1/leads', ['name' => 'Prospective Clinic', 'type' => 'Clinic']);

        $response->assertCreated();
        $this->assertDatabaseHas('leads', ['name' => 'Prospective Clinic', 'tenant_id' => $tenant->id]);
    }

    public function test_warehouse_employee_cannot_create_a_lead(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Employee');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/leads', ['name' => 'Prospective Clinic', 'type' => 'Clinic']);

        $response->assertForbidden();
    }
}

<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class CustomerStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_service_creates_a_customer(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Nile Valley Vet Clinic',
            'type' => 'Clinic',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', ['name' => 'Nile Valley Vet Clinic', 'tenant_id' => $tenant->id]);
    }

    public function test_sales_rep_creating_a_customer_defaults_to_themselves(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Delta Dairy Farm',
            'type' => 'Farm',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sales_rep_id', $rep->id);
    }

    public function test_sales_rep_cannot_create_a_customer_under_another_reps_name(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);
        $otherRep = User::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Delta Dairy Farm',
            'type' => 'Farm',
            'sales_rep_id' => $otherRep->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['sales_rep_id']);
    }

    public function test_warehouse_manager_cannot_create_a_customer(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Delta Dairy Farm',
            'type' => 'Farm',
        ]);

        $response->assertForbidden();
    }
}

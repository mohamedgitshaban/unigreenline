<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class SupplierStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchasing_role_creates_a_supplier(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Purchasing');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/suppliers', ['name' => 'Test Supplier']);

        $response->assertCreated();
        $this->assertDatabaseHas('suppliers', ['name' => 'Test Supplier', 'tenant_id' => $tenant->id]);
        // Regression: pay_terms/currency/status/balance have DB defaults and
        // weren't submitted — the response must reflect them immediately.
        $response->assertJsonPath('data.pay_terms', 'Net 30');
        $response->assertJsonPath('data.currency', 'EGP');
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonPath('data.balance', '0.00');
    }

    public function test_sales_rep_cannot_create_a_supplier(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/suppliers', ['name' => 'Test Supplier']);

        $response->assertForbidden();
    }
}

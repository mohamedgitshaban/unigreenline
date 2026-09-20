<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Tests\TestCase;

class CustomerUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_can_update_their_own_customer(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $customer = Customer::factory()->recycle($tenant)->create(['sales_rep_id' => $rep->id]);

        $response = $this->putJson("/api/v1/customers/{$customer->id}", ['classification' => 'A+']);

        $response->assertOk();
        $this->assertSame('A+', $customer->fresh()->classification);
    }

    public function test_sales_rep_cannot_update_another_reps_customer(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $customer = Customer::factory()->recycle($tenant)->create();

        $response = $this->putJson("/api/v1/customers/{$customer->id}", ['classification' => 'A+']);

        $response->assertForbidden();
    }
}

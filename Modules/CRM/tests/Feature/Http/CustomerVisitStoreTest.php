<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Tests\TestCase;

class CustomerVisitStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_logs_a_visit_defaulting_to_themselves_as_rep(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);
        $customer = Customer::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/visits', [
            'customer_id' => $customer->id,
            'visit_date' => now()->toDateString(),
            'outcome' => 'positive',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.rep.id', $rep->id);
    }
}

<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class DeliveryDeliverTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_manager_marks_a_pending_delivery_delivered(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $manager = User::factory()->recycle($tenant)->create();
        $manager->assignRole('Sales Manager');
        Sanctum::actingAs($manager);

        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        $delivery = Delivery::factory()->recycle([$tenant, $customer])->for($order, 'salesOrder')->create(['status' => 'pending']);

        $response = $this->putJson("/api/v1/deliveries/{$delivery->id}/deliver");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'delivered');
        $this->assertNotNull($delivery->fresh()->delivered_at);
    }

    public function test_sales_rep_cannot_deliver_another_reps_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $otherRep = User::factory()->recycle($tenant)->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create(['sales_rep_id' => $otherRep->id]);
        $delivery = Delivery::factory()->recycle([$tenant, $customer])->for($order, 'salesOrder')->create();

        $response = $this->putJson("/api/v1/deliveries/{$delivery->id}/deliver");

        $response->assertForbidden();
    }
}

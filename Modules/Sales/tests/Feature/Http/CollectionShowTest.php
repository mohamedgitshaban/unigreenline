<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class CollectionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_cannot_view_a_collection_from_another_reps_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $otherRep = User::factory()->recycle($tenant)->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create(['sales_rep_id' => $otherRep->id]);
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create(['so_id' => $order->id]);
        $collection = Collection::factory()->recycle([$tenant, $customer, $invoice])->create();

        $response = $this->getJson("/api/v1/collections/{$collection->id}");

        $response->assertForbidden();
    }

    public function test_accountant_can_view_a_collection(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create(['so_id' => $order->id]);
        $collection = Collection::factory()->recycle([$tenant, $customer, $invoice])->create(['collected_by' => $user->id]);

        $response = $this->getJson("/api/v1/collections/{$collection->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $collection->id);
        $response->assertJsonPath('data.invoice.id', $invoice->id);
        $response->assertJsonPath('data.collected_by.id', $user->id);
    }

    public function test_a_collection_from_another_tenant_is_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $collection = Collection::factory()->create();

        $response = $this->getJson("/api/v1/collections/{$collection->id}");

        $response->assertNotFound();
    }
}

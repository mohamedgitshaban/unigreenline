<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerVisit;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class CustomerIndexShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_only_sees_their_own_customers(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $own = Customer::factory()->recycle($tenant)->create(['sales_rep_id' => $rep->id]);
        $others = Customer::factory()->recycle($tenant)->create();

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($own->id));
        $this->assertFalse($ids->contains($others->id));
    }

    public function test_customer_service_sees_every_customer(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        Customer::factory()->recycle($tenant)->count(3)->create();

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_show_includes_order_invoice_and_visit_counts(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create();
        $visit = CustomerVisit::factory()->recycle([$tenant, $customer])->create();

        $response = $this->getJson("/api/v1/customers/{$customer->id}");

        $response->assertOk();
        $response->assertJsonPath('data.orders_count', 1);
        $response->assertJsonPath('data.invoices_count', 1);
        $response->assertJsonPath('data.visits_count', 1);
        $response->assertJsonMissingPath('data.orders');

        $this->getJson("/api/v1/customers/{$customer->id}/orders")->assertOk()->assertJsonPath('data.0.id', $order->id);
        $this->getJson("/api/v1/customers/{$customer->id}/invoices")->assertOk()->assertJsonPath('data.0.id', $invoice->id);
        $this->getJson("/api/v1/customers/{$customer->id}/visits")->assertOk()->assertJsonPath('data.0.id', $visit->id);
    }

    public function test_sales_rep_cannot_view_another_reps_customer(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $customer = Customer::factory()->recycle($tenant)->create();

        $response = $this->getJson("/api/v1/customers/{$customer->id}");

        $response->assertForbidden();
    }
}

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
use Modules\Sales\Models\SalesOrderLine;
use Tests\TestCase;

class InvoiceShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_cannot_view_an_invoice_from_another_reps_order(): void
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

        $response = $this->getJson("/api/v1/invoices/{$invoice->id}");

        $response->assertForbidden();
    }

    public function test_accountant_can_view_any_invoice_with_line_and_collection_counts(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        SalesOrderLine::factory()->count(3)->recycle($tenant)->create(['so_id' => $order->id]);
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create(['so_id' => $order->id]);
        $collection = Collection::factory()->recycle([$tenant, $customer, $invoice])->create();

        $response = $this->getJson("/api/v1/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $invoice->id);
        $response->assertJsonPath('data.lines_count', 3);
        $response->assertJsonPath('data.collections_count', 1);

        $this->getJson("/api/v1/invoices/{$invoice->id}/lines?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->getJson("/api/v1/invoices/{$invoice->id}/collections")
            ->assertOk()
            ->assertJsonPath('data.0.id', $collection->id);
    }

    public function test_sales_rep_cannot_list_collections_of_another_reps_invoice(): void
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

        $this->getJson("/api/v1/invoices/{$invoice->id}/collections")->assertForbidden();
    }
}

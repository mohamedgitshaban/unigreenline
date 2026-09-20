<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
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

    public function test_accountant_can_view_any_invoice_with_lines_and_collections(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create(['so_id' => $order->id]);

        $response = $this->getJson("/api/v1/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $invoice->id);
        $response->assertJsonStructure(['data' => ['lines', 'collections']]);
    }
}

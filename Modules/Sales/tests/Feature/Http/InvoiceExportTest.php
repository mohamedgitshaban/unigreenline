<?php

namespace Modules\Sales\Tests\Feature\Http;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class InvoiceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_export_invoices_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create(['so_id' => $order->id]);

        $this->get('/api/v1/invoices?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/invoices-.*\.csv/', function (GenericExport $export) use ($invoice) {
            $this->assertSame($invoice->id, $export->collection()->first()[0]);

            return true;
        });
    }

    /**
     * Sales Rep can view invoices (sales.view) but has no export capability
     * on any module (spec §2) — this is the interesting case, distinct from
     * simply lacking view access: viewAny passes, export must still 403.
     */
    public function test_sales_rep_can_view_but_not_export_invoices(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/invoices')->assertOk();
        $this->get('/api/v1/invoices?export=csv')->assertForbidden();
    }
}

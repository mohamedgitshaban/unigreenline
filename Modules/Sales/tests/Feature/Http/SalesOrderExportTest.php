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
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class SalesOrderExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_manager_can_export_sales_orders_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create(['total' => 351.98]);

        $this->get('/api/v1/sales-orders?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/sales-orders-.*\.csv/', function (GenericExport $export) use ($order) {
            $this->assertSame($order->id, $export->collection()->first()[0]);

            return true;
        });
    }

    public function test_sales_rep_without_export_permission_is_forbidden(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        // Sales Rep has sales.view/add/edit/print but not sales.export (spec §2).
        $user = User::factory()->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/sales-orders')->assertOk();
        $this->get('/api/v1/sales-orders?export=csv')->assertForbidden();
    }
}

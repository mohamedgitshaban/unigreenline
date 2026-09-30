<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Purchasing\Models\PurchaseOrder;
use Tests\TestCase;

class PurchaseOrderExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uses Administrator, not the Purchasing role — Purchasing lacks
     * purchasing.export entirely per spec §2 (see the second test below).
     */
    public function test_administrator_can_export_purchase_orders_as_xlsx(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $order = PurchaseOrder::factory()->recycle($tenant)->create(['total' => 2000]);

        $this->get('/api/v1/purchase-orders?export=xlsx')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/purchase-orders-.*\.xlsx/', function (GenericExport $export) use ($order) {
            $this->assertSame($order->id, $export->collection()->first()[0]);

            return true;
        });
    }

    public function test_purchasing_role_can_view_but_not_export_purchase_orders(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Purchasing');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/purchase-orders')->assertOk();
        $this->get('/api/v1/purchase-orders?export=csv')->assertForbidden();
    }
}

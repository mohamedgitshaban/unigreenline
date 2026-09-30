<?php

namespace Modules\Sales\Tests\Feature\Http;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Sales\Models\Delivery;
use Tests\TestCase;

class DeliveryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_manager_can_export_deliveries_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $delivery = Delivery::factory()->recycle($tenant)->create(['status' => 'delivered']);

        $this->get('/api/v1/deliveries?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/deliveries-.*\.csv/', function (GenericExport $export) use ($delivery) {
            $this->assertSame($delivery->id, $export->collection()->first()[0]);

            return true;
        });
    }

    public function test_sales_rep_can_view_but_not_export_deliveries(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/deliveries')->assertOk();
        $this->get('/api/v1/deliveries?export=csv')->assertForbidden();
    }
}

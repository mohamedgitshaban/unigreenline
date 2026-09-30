<?php

namespace Modules\Sales\Tests\Feature\Http;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Sales\Models\Collection as CollectionModel;
use Tests\TestCase;

class CollectionExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_export_collections_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $collection = CollectionModel::factory()->recycle($tenant)->create(['amount' => 300]);

        $this->get('/api/v1/collections?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/collections-.*\.csv/', function (GenericExport $export) use ($collection) {
            $this->assertSame($collection->id, $export->collection()->first()[0]);

            return true;
        });
    }

    public function test_sales_rep_can_view_but_not_export_collections(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/collections')->assertOk();
        $this->get('/api/v1/collections?export=csv')->assertForbidden();
    }
}

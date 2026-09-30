<?php

namespace Modules\Sales\Tests\Feature\Http;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Sales\Models\ReturnRecord;
use Tests\TestCase;

class ReturnExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_manager_can_export_returns_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $return = ReturnRecord::factory()->recycle($tenant)->create(['type' => 'Damaged']);

        $this->get('/api/v1/returns?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/returns-.*\.csv/', function (GenericExport $export) use ($return) {
            $this->assertSame($return->id, $export->collection()->first()[0]);

            return true;
        });
    }

    /**
     * Purchasing role can view returns (purchasing.view) but has no Export
     * capability at all per spec §2's role table — unlike every other role
     * in this module, its row simply omits Export.
     */
    public function test_purchasing_role_can_view_but_not_export_returns(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Purchasing');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/returns')->assertOk();
        $this->get('/api/v1/returns?export=csv')->assertForbidden();
    }
}

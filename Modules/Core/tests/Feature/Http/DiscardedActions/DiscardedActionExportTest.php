<?php

namespace Modules\Core\Tests\Feature\Http\DiscardedActions;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\DiscardedAction;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class DiscardedActionExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_export_discarded_actions_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $action = DiscardedAction::factory()->recycle($tenant)->create(['label' => 'Export Test Draft']);

        $this->get('/api/v1/discarded-actions?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/discarded-actions-.*\.csv/', function (GenericExport $export) use ($action) {
            $this->assertSame($action->id, $export->collection()->first()[0]);
            $this->assertSame('Export Test Draft', $export->collection()->first()[3]);

            return true;
        });
    }

    public function test_sales_manager_without_admin_permissions_is_forbidden(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $this->get('/api/v1/discarded-actions?export=csv')->assertForbidden();
    }
}

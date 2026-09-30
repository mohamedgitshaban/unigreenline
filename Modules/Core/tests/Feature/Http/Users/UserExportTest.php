<?php

namespace Modules\Core\Tests\Feature\Http\Users;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class UserExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_export_users_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $target = User::factory()->recycle($tenant)->create(['name' => 'Export Test User']);
        $target->assignRole('Sales Rep');

        $this->get('/api/v1/users?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/users-.*\.csv/', function (GenericExport $export) use ($target) {
            $this->assertSame(['ID', 'Name', 'Email', 'Role', 'Status', 'Last Login', 'Created At'], $export->headings());
            $row = $export->collection()->firstWhere(fn ($r) => $r[0] === $target->id);
            $this->assertNotNull($row);
            $this->assertSame('Export Test User', $row[1]);
            $this->assertSame('Sales Rep', $row[3]);

            return true;
        });
    }

    public function test_password_is_never_present_in_the_export(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $this->get('/api/v1/users?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/users-.*\.csv/', function (GenericExport $export) {
            $this->assertNotContains('Password', $export->headings());

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

        $this->get('/api/v1/users?export=csv')->assertForbidden();
    }
}

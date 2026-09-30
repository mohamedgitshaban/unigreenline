<?php

namespace Modules\Core\Tests\Feature\Http\AuditLog;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Tests\TestCase;

class AuditLogExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_can_export_the_audit_log_as_csv_with_the_hash_chain(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Auditor');
        Sanctum::actingAs($user);

        $entry = $this->app->make(AuditLogService::class)->record([
            'module' => 'inventory', 'entity_type' => 'Warehouse', 'operation' => 'INSERT', 'tenant_id' => $tenant->id,
        ]);

        $this->get('/api/v1/audit-log?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/audit-log-.*\.csv/', function (GenericExport $export) use ($entry) {
            $row = $export->collection()->first();
            $this->assertSame($entry->id, $row[0]);
            $this->assertSame($entry->entry_hash, $row[9]);

            return true;
        });
    }

    public function test_sales_manager_without_admin_audit_is_forbidden(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $this->get('/api/v1/audit-log?export=csv')->assertForbidden();
    }
}

<?php

namespace Modules\Core\Tests\Feature\Http\AuditLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Tests\TestCase;

class AuditLogVerifyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_intact_chain(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $service = $this->app->make(AuditLogService::class);
        $service->record(['module' => 'inventory', 'entity_type' => 'Warehouse', 'operation' => 'INSERT', 'tenant_id' => $tenant->id]);

        $response = $this->postJson('/api/v1/audit-log/verify-integrity');

        $response->assertOk();
        $response->assertJson(['intact' => true, 'broken_at' => null]);
    }

    public function test_reports_the_first_tampered_row(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $service = $this->app->make(AuditLogService::class);
        $entry = $service->record(['module' => 'inventory', 'entity_type' => 'Warehouse', 'operation' => 'INSERT', 'tenant_id' => $tenant->id]);
        $service->record(['module' => 'inventory', 'entity_type' => 'Warehouse', 'operation' => 'UPDATE', 'tenant_id' => $tenant->id]);

        DB::table('audit_log')->where('id', $entry->id)->update(['operation' => 'DELETE']);

        $response = $this->postJson('/api/v1/audit-log/verify-integrity');

        $response->assertOk();
        $response->assertJson(['intact' => false, 'broken_at' => $entry->id]);
    }

    public function test_sales_rep_without_admin_audit_permission_is_forbidden(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/audit-log/verify-integrity');

        $response->assertForbidden();
    }
}

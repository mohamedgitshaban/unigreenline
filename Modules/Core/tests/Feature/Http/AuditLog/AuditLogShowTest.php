<?php

namespace Modules\Core\Tests\Feature\Http\AuditLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Tests\TestCase;

class AuditLogShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_can_view_an_audit_log_entry(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Auditor');
        Sanctum::actingAs($user);

        $record = $this->app->make(AuditLogService::class)->record(['module' => 'inventory', 'entity_type' => 'Warehouse', 'operation' => 'INSERT', 'tenant_id' => $tenant->id]);

        $response = $this->getJson("/api/v1/audit-log/{$record->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $record->id);
    }

    public function test_an_audit_log_entry_from_another_tenant_is_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $record = $this->app->make(AuditLogService::class)->record(['module' => 'inventory', 'entity_type' => 'Warehouse', 'operation' => 'INSERT', 'tenant_id' => Tenant::factory()->create()->id]);

        $response = $this->getJson("/api/v1/audit-log/{$record->id}");

        $response->assertNotFound();
    }
}

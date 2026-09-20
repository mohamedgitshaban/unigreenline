<?php

namespace Modules\Core\Tests\Feature\Http\AuditLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/audit-log');

        $response->assertUnauthorized();
    }

    public function test_sales_rep_without_admin_audit_permission_is_forbidden(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/audit-log');

        $response->assertForbidden();
    }

    public function test_auditor_can_view_a_paginated_audit_log(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Auditor');
        Sanctum::actingAs($user);

        $service = $this->app->make(AuditLogService::class);
        foreach (range(1, 3) as $i) {
            $service->record([
                'module' => 'inventory',
                'entity_type' => 'Warehouse',
                'entity_id' => "wh-{$i}",
                'operation' => 'INSERT',
                'tenant_id' => $tenant->id,
            ]);
        }

        $response = $this->getJson('/api/v1/audit-log?per_page=2');

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', 2);
        $this->assertCount(2, $response->json('data'));
        // Most recent first.
        $this->assertSame('wh-3', $response->json('data.0.entity_id'));
    }

    public function test_can_filter_by_module(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Auditor');
        Sanctum::actingAs($user);

        $service = $this->app->make(AuditLogService::class);
        $service->record(['module' => 'inventory', 'entity_type' => 'Warehouse', 'operation' => 'INSERT', 'tenant_id' => $tenant->id]);
        $service->record(['module' => 'sales', 'entity_type' => 'SalesOrder', 'operation' => 'INSERT', 'tenant_id' => $tenant->id]);

        $response = $this->getJson('/api/v1/audit-log?module=sales');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.module', 'sales');
    }
}

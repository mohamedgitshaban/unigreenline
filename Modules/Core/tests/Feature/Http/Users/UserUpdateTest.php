<?php

namespace Modules\Core\Tests\Feature\Http\Users;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class UserUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_change_a_users_role_and_status(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $target = User::factory()->recycle($tenant)->create();
        $target->assignRole('Sales Rep');

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'role' => 'Sales Manager',
            'status' => 'suspended',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.role', 'Sales Manager');
        $response->assertJsonPath('data.status', 'suspended');
        $this->assertTrue($target->fresh()->hasRole('Sales Manager'));
        $this->assertFalse($target->fresh()->hasRole('Sales Rep'));
    }

    public function test_can_replace_warehouse_assignments(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $target = User::factory()->recycle($tenant)->create();
        $target->assignRole('Warehouse Employee');
        $oldWarehouse = Warehouse::factory()->recycle($tenant)->create();
        $oldWarehouse->users()->attach($target);

        $newWarehouse = Warehouse::factory()->recycle($tenant)->create();

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'warehouse_ids' => [$newWarehouse->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.warehouse_ids', [$newWarehouse->id]);
        $this->assertDatabaseMissing('user_warehouses', [
            'user_id' => $target->id,
            'warehouse_id' => $oldWarehouse->id,
        ]);
    }

    public function test_password_cannot_be_changed_through_this_endpoint(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $target = User::factory()->recycle($tenant)->create();
        $originalPassword = $target->password;

        $this->putJson("/api/v1/users/{$target->id}", [
            'password' => 'should-be-ignored',
        ])->assertOk();

        $this->assertSame($originalPassword, $target->fresh()->password);
    }

    public function test_auditor_cannot_update_a_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $auditor = User::factory()->recycle($tenant)->create();
        $auditor->assignRole('Auditor');
        Sanctum::actingAs($auditor);

        $target = User::factory()->recycle($tenant)->create();

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'status' => 'suspended',
        ]);

        $response->assertForbidden();
    }
}

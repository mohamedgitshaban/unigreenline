<?php

namespace Modules\Core\Tests\Feature\Http\Roles;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_admin_creates_a_role_with_permissions_and_assigns_it_to_a_user(): void
    {
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/roles', ['name' => 'Shift Lead', 'permissions' => ['sales.view', 'sales.edit', 'sales.delete']])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Shift Lead')
            ->assertJsonPath('data.permissions', ['sales.delete', 'sales.edit', 'sales.view']);

        $this->postJson('/api/v1/users', [
            'name' => 'Lead', 'email' => 'lead@example.com', 'password' => 'password123', 'role' => 'Shift Lead',
        ])->assertCreated();

        $lead = User::query()->where('email', 'lead@example.com')->firstOrFail();
        $this->assertTrue($lead->can('sales.delete'));
        $this->assertFalse($lead->can('sales.add'));
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Role', 'operation' => 'INSERT']);
    }

    public function test_admin_changes_the_permissions_a_role_grants(): void
    {
        $this->actingAsRole('Administrator');
        $role = Role::findByName('Sales Rep', 'web');
        $this->assertFalse($role->hasPermissionTo('sales.delete'));

        $this->putJson("/api/v1/roles/{$role->id}", ['permissions' => ['sales.view', 'sales.delete']])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['sales.delete', 'sales.view']);

        $this->assertTrue($role->fresh()->hasPermissionTo('sales.delete'));
        $this->assertFalse($role->fresh()->hasPermissionTo('sales.add'));
    }

    public function test_unknown_permission_is_rejected(): void
    {
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/roles', ['name' => 'Bad', 'permissions' => ['sales.fly']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_the_administrator_role_cannot_be_changed_or_deleted(): void
    {
        $this->actingAsRole('Administrator');
        $admin = Role::findByName('Administrator', 'web');

        $this->putJson("/api/v1/roles/{$admin->id}", ['permissions' => []])->assertForbidden();
        $this->deleteJson("/api/v1/roles/{$admin->id}")->assertForbidden();
        $this->assertSame(81, $admin->fresh()->permissions()->count());
    }

    public function test_a_role_with_users_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $this->actingAsRole('Administrator');
        $used = Role::findByName('Auditor', 'web');
        User::factory()->recycle($this->tenant)->create()->assignRole('Auditor');
        $unused = Role::create(['name' => 'Temp', 'guard_name' => 'web']);

        $this->deleteJson("/api/v1/roles/{$used->id}")->assertConflict();
        $this->deleteJson("/api/v1/roles/{$unused->id}")->assertNoContent();

        $this->assertDatabaseMissing('roles', ['name' => 'Temp']);
        $this->assertDatabaseHas('roles', ['name' => 'Auditor']);
    }

    public function test_assigns_existing_users_to_a_role(): void
    {
        $this->actingAsRole('Administrator');
        $role = Role::create(['name' => 'Temp', 'guard_name' => 'web']);
        $role->givePermissionTo('inventory.view');
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Sales Rep');

        $this->putJson("/api/v1/roles/{$role->id}/users", ['user_ids' => [$user->id]])
            ->assertOk()
            ->assertJsonPath('data.users_count', 1);

        $this->assertTrue($user->fresh()->hasRole('Temp'));
        $this->assertFalse($user->fresh()->hasRole('Sales Rep'));
    }

    public function test_lists_roles_and_grouped_permissions(): void
    {
        $this->actingAsRole('Auditor');

        $this->getJson('/api/v1/roles')->assertOk()->assertJsonCount(10, 'data');
        $this->getJson('/api/v1/permissions')->assertOk()->assertJsonPath('data.sales.0', 'sales.add');
    }

    public function test_non_admin_cannot_manage_roles(): void
    {
        $this->actingAsRole('Sales Manager');
        $role = Role::findByName('Auditor', 'web');

        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->postJson('/api/v1/roles', ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/roles/{$role->id}", ['name' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/v1/roles/{$role->id}")->assertForbidden();
    }

    public function test_admin_deletes_a_user_but_not_themself(): void
    {
        $admin = $this->actingAsRole('Administrator');
        $target = User::factory()->recycle($this->tenant)->create();

        $this->deleteJson("/api/v1/users/{$target->id}")->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $target->id]);

        $this->deleteJson("/api/v1/users/{$admin->id}")->assertUnprocessable();
    }

    public function test_user_delete_requires_the_delete_permission_and_same_tenant(): void
    {
        $this->actingAsRole('Auditor');
        $target = User::factory()->recycle($this->tenant)->create();
        $this->deleteJson("/api/v1/users/{$target->id}")->assertForbidden();

        $this->actingAsRole('Administrator');
        $other = User::factory()->create();
        $this->deleteJson("/api/v1/users/{$other->id}")->assertNotFound();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}

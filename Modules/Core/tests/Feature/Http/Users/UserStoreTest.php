<?php

namespace Modules\Core\Tests\Feature\Http\Users;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\AuditLog;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class UserStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_create_a_user_with_a_role(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Accountant',
            'email' => 'new.accountant@vetpharma.com',
            'password' => 'a-secure-password',
            'role' => 'Accountant',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.role', 'Accountant');
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('users', [
            'email' => 'new.accountant@vetpharma.com',
            'tenant_id' => $tenant->id,
        ]);

        $created = User::query()->where('email', 'new.accountant@vetpharma.com')->firstOrFail();
        $this->assertTrue(Hash::check('a-secure-password', $created->password));
    }

    public function test_password_is_never_present_in_the_response(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Accountant',
            'email' => 'new.accountant@vetpharma.com',
            'password' => 'a-secure-password',
            'role' => 'Accountant',
        ]);

        $response->assertCreated();
        $response->assertJsonMissingPath('data.password');
    }

    public function test_can_assign_warehouses_on_create(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $warehouse = Warehouse::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Employee',
            'email' => 'new.employee@vetpharma.com',
            'password' => 'a-secure-password',
            'role' => 'Warehouse Employee',
            'warehouse_ids' => [$warehouse->id],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.warehouse_ids', [$warehouse->id]);
        $this->assertDatabaseHas('user_warehouses', [
            'warehouse_id' => $warehouse->id,
        ]);
    }

    public function test_sales_manager_cannot_create_a_user(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New User',
            'email' => 'new.user@vetpharma.com',
            'password' => 'a-secure-password',
            'role' => 'Sales Rep',
        ]);

        $response->assertForbidden();
    }

    public function test_owner_cannot_create_a_user(): void
    {
        // Owner has admin.audit (view) but not admin.add — oversight, not data entry (spec §2).
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New User',
            'email' => 'new.user@vetpharma.com',
            'password' => 'a-secure-password',
            'role' => 'Sales Rep',
        ]);

        $response->assertForbidden();
    }

    public function test_duplicate_email_returns_422(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $existing = User::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Duplicate',
            'email' => $existing->email,
            'password' => 'a-secure-password',
            'role' => 'Sales Rep',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_unknown_role_returns_422(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New User',
            'email' => 'new.user@vetpharma.com',
            'password' => 'a-secure-password',
            'role' => 'Not A Real Role',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['role']);
    }

    public function test_creating_a_user_records_an_audit_log_entry_without_the_password(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/users', [
            'name' => 'New Accountant',
            'email' => 'new.accountant@vetpharma.com',
            'password' => 'a-secure-password',
            'role' => 'Accountant',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_log', [
            'module' => 'admin',
            'entity_type' => 'User',
            'operation' => 'INSERT',
        ]);
        $entry = AuditLog::query()->where('module', 'admin')->firstOrFail();
        $this->assertStringNotContainsString('a-secure-password', json_encode($entry->new_values));
    }
}

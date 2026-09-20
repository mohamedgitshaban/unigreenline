<?php

namespace Modules\Core\Tests\Feature\Http\Users;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class UserIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/users');

        $response->assertUnauthorized();
    }

    public function test_auditor_can_list_users(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Auditor');
        Sanctum::actingAs($user);

        User::factory()->recycle($tenant)->count(2)->create();

        $response = $this->getJson('/api/v1/users');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_sales_rep_without_admin_audit_permission_is_forbidden(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/users');

        $response->assertForbidden();
    }

    public function test_response_is_scoped_to_tenant(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        User::factory()->recycle($otherTenant)->create();

        $response = $this->getJson('/api/v1/users');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}

<?php

namespace Modules\Core\Tests\Feature\Http\DiscardedActions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\DiscardedAction;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class DiscardedActionIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_list_discarded_actions_tenant_wide(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        DiscardedAction::factory()->recycle($tenant)->count(2)->create();

        $response = $this->getJson('/api/v1/discarded-actions');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_sales_rep_without_admin_view_permission_is_forbidden(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/discarded-actions');

        $response->assertForbidden();
    }
}

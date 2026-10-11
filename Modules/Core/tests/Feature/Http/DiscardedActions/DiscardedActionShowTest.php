<?php

namespace Modules\Core\Tests\Feature\Http\DiscardedActions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\DiscardedAction;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class DiscardedActionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_can_view_a_discarded_action(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Auditor');
        Sanctum::actingAs($user);

        $record = DiscardedAction::factory()->recycle($tenant)->create();

        $response = $this->getJson("/api/v1/discarded-actions/{$record->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $record->id);
    }

    public function test_a_discarded_action_from_another_tenant_is_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $record = DiscardedAction::factory()->create();

        $response = $this->getJson("/api/v1/discarded-actions/{$record->id}");

        $response->assertNotFound();
    }
}

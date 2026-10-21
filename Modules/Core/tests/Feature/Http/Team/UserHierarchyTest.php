<?php

namespace Modules\Core\Tests\Feature\Http\Team;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Tests\TestCase;

class UserHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    private function user(string $role, ?User $manager = null, array $attributes = []): User
    {
        $user = User::factory()->recycle($this->tenant)->create(['manager_id' => $manager?->id, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    public function test_a_user_is_created_with_a_manager_and_the_response_shows_it(): void
    {
        $admin = $this->user('Administrator');
        $manager = $this->user('Sales Manager');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/users', [
            'name' => 'New Rep', 'email' => 'rep@example.com', 'password' => 'a-secure-password',
            'role' => 'Sales Rep', 'manager_id' => $manager->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.manager_id', $manager->id)
            ->assertJsonPath('data.manager.name', $manager->name)
            ->assertJsonPath('data.effective_data_scope', 'team');
    }

    public function test_the_manager_must_belong_to_the_same_company(): void
    {
        $admin = $this->user('Administrator');
        $stranger = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/users', [
            'name' => 'New Rep', 'email' => 'rep@example.com', 'password' => 'a-secure-password',
            'role' => 'Sales Rep', 'manager_id' => $stranger->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('manager_id');
    }

    public function test_a_user_cannot_report_to_themselves_or_to_their_own_team(): void
    {
        $admin = $this->user('Administrator');
        $boss = $this->user('Sales Manager');
        $lead = $this->user('Sales Manager', $boss);
        $rep = $this->user('Sales Rep', $lead);
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/users/{$boss->id}", ['manager_id' => $boss->id])->assertUnprocessable()->assertJsonValidationErrors('manager_id');
        $this->putJson("/api/v1/users/{$boss->id}", ['manager_id' => $rep->id])->assertUnprocessable()->assertJsonValidationErrors('manager_id');
        $this->putJson("/api/v1/users/{$rep->id}", ['manager_id' => $boss->id])->assertOk()->assertJsonPath('data.manager_id', $boss->id);
        $this->putJson("/api/v1/users/{$rep->id}", ['manager_id' => null])->assertOk()->assertJsonPath('data.manager_id', null);
    }

    public function test_the_data_scope_can_be_set_per_user(): void
    {
        $admin = $this->user('Administrator');
        $rep = $this->user('Sales Rep');
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/users/{$rep->id}", ['data_scope' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'all')
            ->assertJsonPath('data.effective_data_scope', 'all');

        $this->putJson("/api/v1/users/{$rep->id}", ['data_scope' => 'galaxy'])->assertUnprocessable()->assertJsonValidationErrors('data_scope');
    }

    public function test_scope_follows_the_role_unless_overridden(): void
    {
        $scope = app(DataScope::class);

        $this->assertSame('team', $scope->effective($this->user('Sales Rep')));
        $this->assertSame('all', $scope->effective($this->user('Sales Manager')));
        $this->assertSame('team', $scope->effective($this->user('Sales Manager', null, ['data_scope' => 'team'])));
        $this->assertSame('all', $scope->effective($this->user('Sales Rep', null, ['data_scope' => 'all'])));
    }

    public function test_a_team_scoped_manager_sees_themselves_and_everyone_below_but_nobody_else(): void
    {
        $boss = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $lead = $this->user('Sales Rep', $boss);
        $rep = $this->user('Sales Rep', $lead);
        $peer = $this->user('Sales Rep');

        $scope = app(DataScope::class);

        $this->assertEqualsCanonicalizing([$boss->id, $lead->id, $rep->id], $scope->visibleUserIds($boss));
        $this->assertEqualsCanonicalizing([$lead->id, $rep->id], $scope->visibleUserIds($lead));
        $this->assertSame([$rep->id], $scope->visibleUserIds($rep));
        $this->assertTrue($scope->canSee($boss, $rep->id));
        $this->assertFalse($scope->canSee($lead, $boss->id));
        $this->assertFalse($scope->canSee($boss, $peer->id));
        $this->assertFalse($scope->canSee($boss, null));
    }

    public function test_a_user_who_sees_everything_has_no_restriction_and_may_see_ownerless_records(): void
    {
        $manager = $this->user('Sales Manager');
        $scope = app(DataScope::class);

        $this->assertNull($scope->visibleUserIds($manager));
        $this->assertTrue($scope->canSee($manager, null));
        $this->assertTrue($scope->canAssignTo($manager, $this->user('Sales Rep')->id));
    }

    public function test_records_can_only_be_handed_to_yourself_or_your_team(): void
    {
        $boss = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $rep = $this->user('Sales Rep', $boss);
        $peer = $this->user('Sales Rep');
        $stranger = User::factory()->create();
        $scope = app(DataScope::class);

        $this->assertTrue($scope->canAssignTo($boss, $boss->id));
        $this->assertTrue($scope->canAssignTo($boss, $rep->id));
        $this->assertFalse($scope->canAssignTo($boss, $peer->id));
        $this->assertFalse($scope->canAssignTo($boss, $stranger->id));
    }

    public function test_moving_a_user_under_another_manager_changes_what_the_managers_see(): void
    {
        $admin = $this->user('Administrator');
        $first = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $second = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $rep = $this->user('Sales Rep', $first);
        $scope = app(DataScope::class);

        $this->assertContains($rep->id, $scope->visibleUserIds($first));

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/users/{$rep->id}", ['manager_id' => $second->id])->assertOk();

        $this->assertNotContains($rep->id, $scope->visibleUserIds($first->refresh()));
        $this->assertContains($rep->id, $scope->visibleUserIds($second->refresh()));
    }

    public function test_my_team_lists_everyone_below_with_their_depth(): void
    {
        $boss = $this->user('Sales Manager');
        $lead = $this->user('Sales Manager', $boss);
        $repA = $this->user('Sales Rep', $lead);
        $repB = $this->user('Sales Rep', $boss);
        $this->user('Sales Rep');
        Sanctum::actingAs($boss);

        $response = $this->getJson('/api/v1/team')->assertOk();

        $response->assertJsonPath('data.user.id', $boss->id)
            ->assertJsonPath('data.direct_reports', 2)
            ->assertJsonCount(3, 'data.members');

        $depths = collect($response->json('data.members'))->pluck('depth', 'id')->all();
        $this->assertSame([$lead->id => 1, $repB->id => 1, $repA->id => 2], $depths);
    }

    public function test_a_user_with_nobody_below_has_an_empty_team_and_sees_their_manager(): void
    {
        $boss = $this->user('Sales Manager');
        $rep = $this->user('Sales Rep', $boss);
        Sanctum::actingAs($rep);

        $this->getJson('/api/v1/team')
            ->assertOk()
            ->assertJsonPath('data.manager.id', $boss->id)
            ->assertJsonPath('data.data_scope', 'team')
            ->assertJsonCount(0, 'data.members');
    }

    public function test_a_users_team_can_be_viewed_by_someone_who_may_see_that_user(): void
    {
        $admin = $this->user('Administrator');
        $boss = $this->user('Sales Manager');
        $rep = $this->user('Sales Rep', $boss);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/users/{$boss->id}/team")
            ->assertOk()
            ->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.members.0.id', $rep->id);
    }

    public function test_a_users_team_of_another_company_is_not_found(): void
    {
        $admin = $this->user('Administrator');
        $stranger = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/users/{$stranger->id}/team")->assertNotFound();
    }

    public function test_a_team_scoped_user_cannot_view_the_team_of_someone_outside_theirs(): void
    {
        $boss = $this->user('Sales Manager');
        $rep = $this->user('Sales Rep', $boss);
        $peer = $this->user('Sales Rep');
        $rep->givePermissionTo('users.team');
        Sanctum::actingAs($rep);

        $this->getJson("/api/v1/users/{$rep->id}/team")->assertOk();
        $this->getJson("/api/v1/users/{$peer->id}/team")->assertForbidden();
    }
}

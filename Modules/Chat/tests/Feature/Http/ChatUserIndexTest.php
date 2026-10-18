<?php

namespace Modules\Chat\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class ChatUserIndexTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/chat-users')->assertUnauthorized();
    }

    public function test_lists_active_colleagues_of_the_callers_tenant_by_name_and_hides_everything_else(): void
    {
        $this->actingAsEmployee();
        User::factory()->recycle($this->tenant)->create(['name' => 'Zeinab Hassan', 'avatar_initials' => 'ZH']);
        User::factory()->recycle($this->tenant)->create(['name' => 'Amr Said']);
        User::factory()->recycle($this->tenant)->create(['name' => 'Inactive Ian', 'status' => 'inactive']);
        User::factory()->recycle($this->tenant)->create(['name' => 'Suspended Sue', 'status' => 'suspended']);
        User::factory()->create(['name' => 'Other Tenant Olga']);

        $response = $this->getJson('/api/v1/chat-users');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Amr Said')
            ->assertJsonPath('data.1.name', 'Zeinab Hassan')
            ->assertJsonPath('data.1.avatar', 'ZH')
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.status');
    }

    public function test_searches_colleagues_by_name(): void
    {
        $this->actingAsEmployee();
        User::factory()->recycle($this->tenant)->create(['name' => 'Alice Morgan']);
        User::factory()->recycle($this->tenant)->create(['name' => 'Bob Stone']);

        $this->getJson('/api/v1/chat-users?search=ali')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alice Morgan');
    }

    public function test_search_treats_sql_wildcards_literally(): void
    {
        $this->actingAsEmployee();
        User::factory()->recycle($this->tenant)->create(['name' => 'Alice Morgan']);

        $this->getJson('/api/v1/chat-users?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function actingAsEmployee(): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Employee');
        Sanctum::actingAs($user);

        return $user;
    }
}

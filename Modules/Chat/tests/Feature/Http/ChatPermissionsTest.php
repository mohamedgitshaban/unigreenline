<?php

namespace Modules\Chat\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\Conversation;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionCatalogue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChatPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_a_role_without_chat_permissions_gets_403_even_in_its_own_conversation(): void
    {
        Role::findOrCreate('Locked Out', 'web');
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Locked Out');
        Sanctum::actingAs($user);
        $conversation = Conversation::factory()->between($user, User::factory()->recycle($this->tenant)->create())->create();

        $this->getJson('/api/v1/conversations')->assertForbidden();
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertForbidden();
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Hi'])->assertForbidden();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_every_seeded_role_can_use_chat(): void
    {
        $chatPermissions = PermissionCatalogue::all()->where('module', 'chat')->keys()->all();

        $rolesMissingSome = Role::query()->with('permissions')->get()
            ->filter(fn (Role $role) => array_diff($chatPermissions, $role->permissions->pluck('name')->all()) !== [])
            ->pluck('name')
            ->all();

        $this->assertNotSame([], $chatPermissions);
        $this->assertSame([], $rolesMissingSome);
    }

    public function test_the_migration_gives_a_role_that_already_existed_every_chat_permission(): void
    {
        $existing = Role::findOrCreate('Imported From Before Chat', 'web');
        $migration = require base_path('Modules/Chat/database/migrations/2026_10_18_102240_grant_chat_permissions_to_existing_roles.php');

        $migration->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $granted = $existing->fresh()->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame(
            ['chat-users.view', 'conversations.create', 'conversations.read', 'conversations.view', 'messages.create', 'messages.view'],
            $granted,
        );
    }
}

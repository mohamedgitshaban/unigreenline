<?php

namespace Modules\Chat\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    /**
     * Every endpoint that takes a conversation, as a role that can chat and as an administrator.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function endpointsOnAConversation(): array
    {
        $cases = [];

        foreach (['Employee', 'Administrator'] as $role) {
            foreach (['show' => ['GET', ''], 'mark read' => ['POST', '/read'], 'list messages' => ['GET', '/messages'], 'send message' => ['POST', '/messages']] as $name => [$method, $suffix]) {
                $cases["{$name} as {$role}"] = [$role, $method, $suffix];
            }
        }

        return $cases;
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/conversations')->assertUnauthorized();
    }

    public function test_starts_a_conversation_with_a_colleague_and_returns_201(): void
    {
        $me = $this->actingAsRole('Employee');
        $alice = $this->colleague('Alice');

        $response = $this->postJson('/api/v1/conversations', ['user_id' => $alice->id]);

        $response->assertCreated()
            ->assertJsonPath('data.participant.id', $alice->id)
            ->assertJsonPath('data.participant.name', 'Alice')
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.last_message', null);
        $conversationId = $response->json('data.id');
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseHas('conversation_participants', ['conversation_id' => $conversationId, 'user_id' => $me->id]);
        $this->assertDatabaseHas('conversation_participants', ['conversation_id' => $conversationId, 'user_id' => $alice->id]);
    }

    public function test_starting_a_conversation_again_returns_the_existing_one_with_200_from_either_side(): void
    {
        $me = $this->actingAsRole('Employee');
        $alice = $this->colleague('Alice');
        $conversationId = $this->postJson('/api/v1/conversations', ['user_id' => $alice->id])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/conversations', ['user_id' => $alice->id])
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId);
        Sanctum::actingAs($alice);
        $this->postJson('/api/v1/conversations', ['user_id' => $me->id])
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId)
            ->assertJsonPath('data.participant.id', $me->id);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_participants', 2);
    }

    public function test_rejects_a_conversation_with_yourself_with_422(): void
    {
        $me = $this->actingAsRole('Employee');

        $this->postJson('/api/v1/conversations', ['user_id' => $me->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id' => 'You cannot start a conversation with yourself.']);

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_rejects_a_user_from_another_tenant_with_422(): void
    {
        $this->actingAsRole('Employee');
        $stranger = User::factory()->create();

        $this->postJson('/api/v1/conversations', ['user_id' => $stranger->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_rejects_an_inactive_user_with_422(): void
    {
        $this->actingAsRole('Employee');
        $inactive = User::factory()->recycle($this->tenant)->create(['status' => 'inactive']);

        $this->postJson('/api/v1/conversations', ['user_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_rejects_a_request_without_a_user_with_422(): void
    {
        $this->actingAsRole('Employee');

        $this->postJson('/api/v1/conversations', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_lists_only_the_callers_conversations_most_recently_active_first_with_unread_counts(): void
    {
        $me = $this->actingAsRole('Employee');
        $alice = $this->colleague('Alice');
        $bob = $this->colleague('Bob');
        $carol = $this->colleague('Carol');
        $withAlice = Conversation::factory()->between($me, $alice)->create(['last_message_at' => now()->subDay()]);
        $withBob = Conversation::factory()->between($me, $bob)->create(['last_message_at' => now()]);
        Conversation::factory()->between($alice, $carol)->create(['last_message_at' => now()]);
        Message::factory()->count(2)->for($withAlice)->for($alice, 'sender')->create();
        Message::factory()->for($withAlice)->for($me, 'sender')->create();
        Message::factory()->for($withBob)->for($bob, 'sender')->create(['body' => 'Lunch?']);

        $response = $this->getJson('/api/v1/conversations');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.participant.name', 'Bob')
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('data.0.last_message.body', 'Lunch?')
            ->assertJsonPath('data.0.last_message.mine', false)
            ->assertJsonPath('data.1.participant.name', 'Alice')
            ->assertJsonPath('data.1.unread_count', 2)
            ->assertJsonPath('data.1.last_message.mine', true);
    }

    public function test_shows_a_conversation_to_its_participant(): void
    {
        $me = $this->actingAsRole('Employee');
        $alice = $this->colleague('Alice');
        $conversation = Conversation::factory()->between($me, $alice)->create();

        $this->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.participant.id', $alice->id)
            ->assertJsonPath('data.last_message', null);
    }

    #[DataProvider('endpointsOnAConversation')]
    public function test_returns_404_for_a_conversation_the_caller_is_not_in(string $role, string $method, string $suffix): void
    {
        $this->actingAsRole($role);
        $conversation = Conversation::factory()->between($this->colleague('Alice'), $this->colleague('Bob'))->create();

        $this->json($method, "/api/v1/conversations/{$conversation->id}{$suffix}")->assertNotFound();
    }

    public function test_marking_a_conversation_read_clears_its_unread_count_until_a_newer_message_arrives(): void
    {
        $me = $this->actingAsRole('Employee');
        $alice = $this->colleague('Alice');
        $conversation = Conversation::factory()->between($me, $alice)->create();
        Message::factory()->count(2)->for($conversation)->for($alice, 'sender')->create();
        $this->getJson("/api/v1/conversations/{$conversation->id}")->assertJsonPath('data.unread_count', 2);

        $this->postJson("/api/v1/conversations/{$conversation->id}/read")->assertNoContent();

        $this->getJson("/api/v1/conversations/{$conversation->id}")->assertJsonPath('data.unread_count', 0);
        Message::factory()->for($conversation)->for($alice, 'sender')->create();
        $this->getJson("/api/v1/conversations/{$conversation->id}")->assertJsonPath('data.unread_count', 1);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function colleague(string $name): User
    {
        $user = User::factory()->recycle($this->tenant)->create(['name' => $name]);
        $user->assignRole('Employee');

        return $user;
    }
}

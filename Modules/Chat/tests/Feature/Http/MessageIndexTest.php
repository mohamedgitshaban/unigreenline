<?php

namespace Modules\Chat\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Chat\Models\MessageAttachment;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class MessageIndexTest extends TestCase
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
        $conversation = Conversation::factory()->create();

        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertUnauthorized();
    }

    public function test_lists_the_conversations_messages_newest_first_with_sender_and_attachments(): void
    {
        $me = $this->actingAsEmployee();
        $alice = $this->colleague('Alice');
        $conversation = Conversation::factory()->between($me, $alice)->create();
        Conversation::factory()->between($me, $this->colleague('Bob'))->create()->messages()->create(['sender_id' => $me->id, 'type' => 'text', 'body' => 'Another chat']);
        Message::factory()->for($conversation)->for($me, 'sender')->create(['body' => 'First']);
        $withFile = Message::factory()->for($conversation)->for($alice, 'sender')->create(['body' => 'Second', 'type' => 'files']);
        $attachment = MessageAttachment::query()->create([
            'message_id' => $withFile->id, 'kind' => 'file', 'path' => 'chat-attachments/x/y/report.pdf',
            'original_name' => 'report.pdf', 'mime_type' => 'application/pdf', 'size' => 2048,
        ]);
        Message::factory()->for($conversation)->for($me, 'sender')->create(['body' => 'Third']);

        $response = $this->getJson("/api/v1/conversations/{$conversation->id}/messages");

        $response->assertOk()
            ->assertJsonPath('data.*.body', ['Third', 'Second', 'First'])
            ->assertJsonPath('data.*.mine', [true, false, true])
            ->assertJsonPath('data.1.sender.name', 'Alice')
            ->assertJsonPath('data.1.type', 'files')
            ->assertJsonPath('data.1.attachments.0.id', $attachment->id)
            ->assertJsonPath('data.1.attachments.0.original_name', 'report.pdf')
            ->assertJsonPath('data.1.attachments.0.url', url("/api/v1/message-attachments/{$attachment->id}"));
    }

    public function test_returns_only_the_messages_after_a_given_message(): void
    {
        $me = $this->actingAsEmployee();
        $alice = $this->colleague('Alice');
        $conversation = Conversation::factory()->between($me, $alice)->create();
        $seen = Message::factory()->for($conversation)->for($alice, 'sender')->create(['body' => 'Seen']);
        Message::factory()->for($conversation)->for($alice, 'sender')->create(['body' => 'New one']);
        Message::factory()->for($conversation)->for($alice, 'sender')->create(['body' => 'New two']);

        $this->getJson("/api/v1/conversations/{$conversation->id}/messages?after={$seen->id}")
            ->assertOk()
            ->assertJsonPath('data.*.body', ['New two', 'New one']);
    }

    public function test_pages_by_cursor_without_repeating_messages(): void
    {
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();
        foreach (['One', 'Two', 'Three'] as $body) {
            Message::factory()->for($conversation)->for($me, 'sender')->create(['body' => $body]);
        }

        $first = $this->getJson("/api/v1/conversations/{$conversation->id}/messages?per_page=2");

        $first->assertOk()->assertJsonPath('data.*.body', ['Three', 'Two']);
        Message::factory()->for($conversation)->for($me, 'sender')->create(['body' => 'Four']);
        $this->getJson($first->json('links.next'))
            ->assertOk()
            ->assertJsonPath('data.*.body', ['One'])
            ->assertJsonPath('links.next', null);
    }

    public function test_rejects_an_invalid_after_value_with_422(): void
    {
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->getJson("/api/v1/conversations/{$conversation->id}/messages?after=not-an-id")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('after');
    }

    public function test_keeps_a_deleted_users_messages_with_no_sender(): void
    {
        $me = $this->actingAsEmployee();
        $alice = $this->colleague('Alice');
        $conversation = Conversation::factory()->between($me, $alice)->create();
        Message::factory()->for($conversation)->for($alice, 'sender')->create(['body' => 'Before I left']);
        $alice->delete();

        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Before I left')
            ->assertJsonPath('data.0.sender', null)
            ->assertJsonPath('data.0.mine', false);
        $this->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.participant', null)
            ->assertJsonPath('data.unread_count', 1);
    }

    private function actingAsEmployee(): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Employee');
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

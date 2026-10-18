<?php

namespace Modules\Chat\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Chat\Models\MessageAttachment;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MessageSendTest extends TestCase
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
     * Files a message must refuse, however they are named: programs, markup that a browser would run, and scripts.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusedAttachments(): array
    {
        return [
            'windows executable' => ['setup.exe', 'application/x-msdownload'],
            'svg image' => ['logo.svg', 'image/svg+xml'],
            'html page' => ['page.html', 'text/html'],
            'php script' => ['shell.php', 'application/x-php'],
        ];
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $conversation = Conversation::factory()->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Hi'])->assertUnauthorized();
    }

    public function test_sends_a_text_message_and_the_recipient_sees_it_as_unread(): void
    {
        $me = $this->actingAsEmployee();
        $alice = $this->colleague('Alice');
        $conversation = Conversation::factory()->between($me, $alice)->create();

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Is the batch ready?']);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.body', 'Is the batch ready?')
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.sender.id', $me->id)
            ->assertJsonPath('data.attachments', []);
        $messageId = $response->json('data.id');
        $this->assertDatabaseHas('messages', ['id' => $messageId, 'conversation_id' => $conversation->id, 'sender_id' => $me->id, 'type' => 'text']);
        $this->assertNotNull($conversation->fresh()->last_message_at);
        $this->assertDatabaseHas('conversation_participants', ['conversation_id' => $conversation->id, 'user_id' => $me->id, 'last_read_message_id' => $messageId]);
        Sanctum::actingAs($alice);
        $this->getJson("/api/v1/conversations/{$conversation->id}")->assertJsonPath('data.unread_count', 1);
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertJsonPath('data.0.mine', false);
    }

    public function test_sends_several_attachments_with_a_caption_and_stores_them_privately_in_the_conversations_folder(): void
    {
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'body' => 'Photos and the paperwork',
            'attachments' => [
                UploadedFile::fake()->image('shelf.jpg'),
                UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('stock.xlsx', 50, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'files')
            ->assertJsonPath('data.body', 'Photos and the paperwork')
            ->assertJsonCount(3, 'data.attachments')
            ->assertJsonPath('data.attachments.*.kind', ['image', 'file', 'file'])
            ->assertJsonPath('data.attachments.*.original_name', ['shelf.jpg', 'invoice.pdf', 'stock.xlsx'])
            ->assertJsonPath('data.attachments.1.mime_type', 'application/pdf')
            ->assertJsonPath('data.attachments.1.size', 100 * 1024)
            ->assertJsonPath('data.attachments.1.duration_seconds', null);
        $attachments = MessageAttachment::query()->orderBy('id')->get();
        $this->assertCount(3, $attachments);
        foreach ($attachments as $attachment) {
            $this->assertStringStartsWith("chat-attachments/{$this->tenant->id}/{$conversation->id}/", $attachment->path);
            Storage::assertExists($attachment->path);
        }
    }

    public function test_sends_a_voice_message_with_its_duration(): void
    {
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'voice' => UploadedFile::fake()->create('recording.webm', 200, 'audio/webm'),
            'voice_duration' => 12,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'voice')
            ->assertJsonPath('data.body', null)
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.kind', 'voice')
            ->assertJsonPath('data.attachments.0.mime_type', 'audio/webm')
            ->assertJsonPath('data.attachments.0.duration_seconds', 12);
        $attachment = MessageAttachment::query()->firstOrFail();
        $this->assertSame($response->json('data.id'), $attachment->message_id);
        Storage::assertExists($attachment->path);
    }

    public function test_rejects_an_empty_message_with_422(): void
    {
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body' => 'A message needs text, an attachment or a voice recording.']);

        $this->assertDatabaseCount('messages', 0);
        $this->assertNull($conversation->fresh()->last_message_at);
    }

    public function test_rejects_a_voice_message_that_also_has_text_with_422(): void
    {
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'body' => 'Listen to this',
            'voice' => UploadedFile::fake()->create('recording.webm', 50, 'audio/webm'),
            'voice_duration' => 5,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['voice' => 'A voice message cannot include text or attachments.']);

        $this->assertDatabaseCount('messages', 0);
        $this->assertSame([], Storage::allFiles());
    }

    public function test_rejects_a_voice_message_that_also_has_attachments_with_422(): void
    {
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'attachments' => [UploadedFile::fake()->image('shelf.jpg')],
            'voice' => UploadedFile::fake()->create('recording.webm', 50, 'audio/webm'),
            'voice_duration' => 5,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['voice' => 'A voice message cannot include text or attachments.']);

        $this->assertDatabaseCount('messages', 0);
        $this->assertSame([], Storage::allFiles());
    }

    public function test_rejects_a_voice_message_without_a_duration_with_422(): void
    {
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'voice' => UploadedFile::fake()->create('recording.webm', 50, 'audio/webm'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voice_duration');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_rejects_a_voice_file_that_is_not_audio_with_422(): void
    {
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'voice' => UploadedFile::fake()->create('recording.pdf', 50, 'application/pdf'),
            'voice_duration' => 5,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voice');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_rejects_a_voice_recording_longer_than_the_limit_with_422(): void
    {
        config(['chat.voice.max_duration_seconds' => 60]);
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'voice' => UploadedFile::fake()->create('recording.webm', 50, 'audio/webm'),
            'voice_duration' => 61,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voice_duration');
    }

    #[DataProvider('refusedAttachments')]
    public function test_rejects_an_attachment_of_a_type_that_is_not_allowed_with_422(string $name, string $mimeType): void
    {
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'attachments' => [UploadedFile::fake()->create($name, 10, $mimeType)],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments.0');

        $this->assertDatabaseCount('messages', 0);
        $this->assertSame([], Storage::allFiles());
    }

    public function test_rejects_more_attachments_than_the_limit_with_422(): void
    {
        config(['chat.attachments.max_files' => 2]);
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'attachments' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
                UploadedFile::fake()->image('three.jpg'),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments');

        $this->assertDatabaseCount('messages', 0);
        $this->assertSame([], Storage::allFiles());
    }

    public function test_rejects_an_attachment_larger_than_the_limit_with_422(): void
    {
        config(['chat.attachments.max_size_kb' => 100]);
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'attachments' => [UploadedFile::fake()->create('big.pdf', 101, 'application/pdf')],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments.0');

        $this->assertSame([], Storage::allFiles());
    }

    public function test_rejects_text_longer_than_the_limit_with_422(): void
    {
        config(['chat.message_max_length' => 10]);
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Eleven char'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_a_failed_send_leaves_no_message_and_no_stored_files(): void
    {
        Exceptions::fake();
        Storage::fake();
        $me = $this->actingAsEmployee();
        $conversation = Conversation::factory()->between($me, $this->colleague('Alice'))->create();
        Message::creating(fn () => throw new RuntimeException('The database went away.'));

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'attachments' => [UploadedFile::fake()->image('shelf.jpg')],
        ])->assertInternalServerError();

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'The database went away.');
        $this->assertSame([], Storage::allFiles());
        $this->assertDatabaseCount('message_attachments', 0);
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

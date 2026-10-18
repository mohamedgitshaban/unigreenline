<?php

namespace Modules\Chat\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Chat\Models\MessageAttachment;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MessageAttachmentDownloadTest extends TestCase
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
     * @return array<string, array{string}>
     */
    public static function rolesThatMustNotReadOthersFiles(): array
    {
        return [
            'employee' => ['Employee'],
            'administrator' => ['Administrator'],
        ];
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/message-attachments/01k0000000000000000000000')->assertUnauthorized();
    }

    public function test_both_participants_download_a_file_under_its_original_name(): void
    {
        Storage::fake();
        $me = $this->actingAsEmployee();
        $alice = $this->colleague('Alice');
        $attachment = $this->attachmentIn(Conversation::factory()->between($me, $alice)->create(), $alice, 'file', UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'));

        $this->get("/api/v1/message-attachments/{$attachment->id}")->assertDownload('invoice.pdf');
        Sanctum::actingAs($alice);
        $this->get("/api/v1/message-attachments/{$attachment->id}")->assertDownload('invoice.pdf');
    }

    public function test_serves_a_voice_message_inline_with_its_recorded_type_and_nosniff(): void
    {
        Storage::fake();
        $me = $this->actingAsEmployee();
        $alice = $this->colleague('Alice');
        $attachment = $this->attachmentIn(Conversation::factory()->between($me, $alice)->create(), $alice, 'voice', UploadedFile::fake()->create('recording.webm', 10, 'audio/webm'));

        $response = $this->get("/api/v1/message-attachments/{$attachment->id}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'audio/webm')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
    }

    #[DataProvider('rolesThatMustNotReadOthersFiles')]
    public function test_returns_404_for_a_file_in_someone_elses_conversation(string $role): void
    {
        Storage::fake();
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);
        $conversation = Conversation::factory()->between($this->colleague('Alice'), $this->colleague('Bob'))->create();
        $attachment = $this->attachmentIn($conversation, $conversation->participants->first()->user, 'file', UploadedFile::fake()->create('private.pdf', 10, 'application/pdf'));

        $this->getJson("/api/v1/message-attachments/{$attachment->id}")->assertNotFound();
    }

    public function test_returns_404_when_the_stored_file_is_gone(): void
    {
        Storage::fake();
        $me = $this->actingAsEmployee();
        $alice = $this->colleague('Alice');
        $attachment = $this->attachmentIn(Conversation::factory()->between($me, $alice)->create(), $alice, 'file', UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'));
        Storage::delete($attachment->path);

        $this->getJson("/api/v1/message-attachments/{$attachment->id}")->assertNotFound();
    }

    private function attachmentIn(Conversation $conversation, User $sender, string $kind, UploadedFile $file): MessageAttachment
    {
        $message = Message::factory()->for($conversation)->for($sender, 'sender')->create();

        return MessageAttachment::query()->create([
            'message_id' => $message->id,
            'kind' => $kind,
            'path' => $file->store(MessageAttachment::directory($this->tenant->id, $conversation->id)),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
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

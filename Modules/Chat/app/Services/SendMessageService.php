<?php

namespace Modules\Chat\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationParticipant;
use Modules\Chat\Models\Message;
use Modules\Chat\Models\MessageAttachment;
use Modules\Core\Models\User;
use Throwable;

class SendMessageService
{
    /**
     * Posts a message: text, files, or one voice recording (the request
     * keeps a voice message separate from text and files). The files are
     * stored first; if saving the message then fails they are removed again,
     * so a failed send leaves nothing behind.
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function send(
        Conversation $conversation,
        User $sender,
        ?string $body,
        array $attachments = [],
        ?UploadedFile $voice = null,
        ?int $voiceDuration = null,
    ): Message {
        $directory = MessageAttachment::directory($conversation->tenant_id, $conversation->id);
        $stored = [];

        try {
            foreach ($attachments as $file) {
                $mimeType = (string) $file->getMimeType();
                $kind = str_starts_with($mimeType, 'image/') ? MessageAttachment::KIND_IMAGE : MessageAttachment::KIND_FILE;

                $stored[] = $this->store($file, $directory, $kind);
            }

            if ($voice !== null) {
                $stored[] = $this->store($voice, $directory, MessageAttachment::KIND_VOICE, $voiceDuration);
            }

            return DB::transaction(function () use ($conversation, $sender, $body, $stored, $voice) {
                $message = $conversation->messages()->create([
                    'sender_id' => $sender->id,
                    'type' => match (true) {
                        $voice !== null => Message::TYPE_VOICE,
                        $stored !== [] => Message::TYPE_FILES,
                        default => Message::TYPE_TEXT,
                    },
                    'body' => $body,
                ]);

                $message->attachments()->createMany($stored);

                $conversation->update(['last_message_at' => $message->created_at]);

                // Your own message is never unread to you.
                ConversationParticipant::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $sender->id)
                    ->update(['last_read_message_id' => $message->id]);

                return $message;
            });
        } catch (Throwable $e) {
            Storage::delete(array_column($stored, 'path'));

            throw $e;
        }
    }

    /**
     * @return array{kind: string, path: string, original_name: string, mime_type: string, size: int, duration_seconds: ?int}
     */
    private function store(UploadedFile $file, string $directory, string $kind, ?int $durationSeconds = null): array
    {
        return [
            'kind' => $kind,
            'path' => $file->store($directory),
            'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'duration_seconds' => $durationSeconds,
        ];
    }
}

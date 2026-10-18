<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['message_id', 'kind', 'path', 'original_name', 'mime_type', 'size', 'duration_seconds'])]
class MessageAttachment extends Model
{
    use HasUlids;

    public const KIND_FILE = 'file';

    public const KIND_IMAGE = 'image';

    public const KIND_VOICE = 'voice';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * Where a conversation's uploads are stored, on the default (private) disk.
     */
    public static function directory(string $tenantId, string $conversationId): string
    {
        return "chat-attachments/{$tenantId}/{$conversationId}";
    }

    /**
     * Images and voice notes are shown or played in the page; anything else is downloaded.
     */
    public function isInline(): bool
    {
        return in_array($this->kind, [self::KIND_IMAGE, self::KIND_VOICE], true);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}

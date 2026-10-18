<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Chat\Database\Factories\MessageFactory;
use Modules\Core\Models\User;

#[Fillable(['conversation_id', 'sender_id', 'type', 'body'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids;

    public const TYPE_TEXT = 'text';

    public const TYPE_FILES = 'files';

    public const TYPE_VOICE = 'voice';

    /**
     * Messages are never edited.
     */
    public const UPDATED_AT = null;

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class)->orderBy('id');
    }

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }
}

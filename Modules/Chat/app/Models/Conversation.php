<?php

namespace Modules\Chat\Models;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Chat\Database\Factories\ConversationFactory;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;

#[Fillable(['tenant_id', 'pair_key', 'last_message_at'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * The same key whichever of the two users asks, so a pair never ends up with two conversations.
     */
    public static function pairKeyFor(string $userId, string $otherUserId): string
    {
        $ids = [$userId, $otherUserId];
        sort($ids);

        return implode(':', $ids);
    }

    /**
     * A conversation is private to its two participants. This is a plain
     * scope, not a policy: CoreServiceProvider's `Gate::before` bypass for
     * Administrator never reaches it, so not even an administrator can read
     * someone else's conversation.
     */
    #[Scope]
    protected function forParticipant(Builder $query, User $user): Builder
    {
        return $query->where('tenant_id', $user->tenant_id)
            ->whereHas('participants', fn (Builder $participants) => $participants->where('user_id', $user->id));
    }

    /**
     * Adds `unread_count`: messages from the other side that come after the
     * last one this user has read.
     */
    #[Scope]
    protected function withUnreadCountFor(Builder $query, User $user): Builder
    {
        return $query->withCount(['messages as unread_count' => self::unreadMessages($user)]);
    }

    public function loadUnreadCountFor(User $user): static
    {
        return $this->loadCount(['messages as unread_count' => self::unreadMessages($user)]);
    }

    /**
     * @return Closure(Builder): void
     */
    private static function unreadMessages(User $user): Closure
    {
        return function (Builder $messages) use ($user): void {
            $messages
                ->where(fn (Builder $sender) => $sender->whereNull('messages.sender_id')->orWhere('messages.sender_id', '!=', $user->id))
                ->whereRaw(
                    "messages.id > COALESCE((select last_read_message_id from conversation_participants where conversation_participants.conversation_id = messages.conversation_id and conversation_participants.user_id = ?), '')",
                    [$user->id],
                );
        };
    }

    public function otherParticipantFor(User $user): ?User
    {
        return $this->participants->firstWhere('user_id', '!=', $user->id)?->user;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    protected static function newFactory(): ConversationFactory
    {
        return ConversationFactory::new();
    }
}

<?php

namespace Modules\Chat\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\Conversation;
use Modules\Core\Models\User;

class StartConversationService
{
    /**
     * Opens the direct conversation between two users, or returns the one
     * they already have. Two requests racing to open the same pair end up
     * with one conversation: the loser hits the unique pair key and reads
     * the winner's.
     *
     * @return array{0: Conversation, 1: bool} The conversation, and whether this call created it.
     */
    public function start(User $user, User $other): array
    {
        $pairKey = Conversation::pairKeyFor($user->id, $other->id);

        $existing = Conversation::query()->where('pair_key', $pairKey)->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $conversation = DB::transaction(function () use ($user, $other, $pairKey) {
                $conversation = Conversation::query()->create([
                    'tenant_id' => $user->tenant_id,
                    'pair_key' => $pairKey,
                ]);

                $conversation->participants()->createMany([
                    ['user_id' => $user->id],
                    ['user_id' => $other->id],
                ]);

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            return [Conversation::query()->where('pair_key', $pairKey)->firstOrFail(), false];
        }

        return [$conversation, true];
    }
}

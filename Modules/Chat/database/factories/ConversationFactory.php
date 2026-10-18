<?php

namespace Modules\Chat\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Chat\Models\Conversation;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pair_key' => Str::lower(Str::ulid()).':'.Str::lower(Str::ulid()),
            'last_message_at' => null,
        ];
    }

    /**
     * A conversation between two real users, who become its participants.
     */
    public function between(User $first, User $second): static
    {
        return $this
            ->state(fn () => [
                'tenant_id' => $first->tenant_id,
                'pair_key' => Conversation::pairKeyFor($first->id, $second->id),
            ])
            ->afterCreating(function (Conversation $conversation) use ($first, $second) {
                $conversation->participants()->createMany([
                    ['user_id' => $first->id],
                    ['user_id' => $second->id],
                ]);
            });
    }
}

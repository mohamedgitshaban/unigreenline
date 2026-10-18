<?php

namespace Modules\Chat\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Core\Models\User;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_id' => User::factory(),
            'type' => Message::TYPE_TEXT,
            'body' => fake()->sentence(),
        ];
    }
}

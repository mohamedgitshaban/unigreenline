<?php

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => null,
            'type' => 'info',
            'title' => fake()->sentence(3),
            'body' => fake()->sentence(),
            'icon' => null,
            'link_module' => null,
            'link_id' => null,
            'unread' => true,
        ];
    }
}

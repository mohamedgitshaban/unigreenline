<?php

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\DiscardedAction;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;

/**
 * @extends Factory<DiscardedAction>
 */
class DiscardedActionFactory extends Factory
{
    protected $model = DiscardedAction::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'user_name' => fake()->name(),
            'type' => 'sales_order',
            'label' => fake()->sentence(3),
            'payload' => ['note' => fake()->sentence()],
        ];
    }
}

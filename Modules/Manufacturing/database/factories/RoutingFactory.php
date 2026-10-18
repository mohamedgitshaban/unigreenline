<?php

namespace Modules\Manufacturing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Manufacturing\Models\Routing;

/**
 * @extends Factory<Routing>
 */
class RoutingFactory extends Factory
{
    protected $model = Routing::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'RT'.fake()->unique()->numerify('####'),
            'name' => fake()->unique()->words(2, true),
            'active' => true,
        ];
    }
}

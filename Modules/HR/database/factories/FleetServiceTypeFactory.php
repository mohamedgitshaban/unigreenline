<?php

namespace Modules\HR\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\HR\Models\FleetServiceType;

/**
 * @extends Factory<FleetServiceType>
 */
class FleetServiceTypeFactory extends Factory
{
    protected $model = FleetServiceType::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true),
            'category' => 'service',
        ];
    }
}

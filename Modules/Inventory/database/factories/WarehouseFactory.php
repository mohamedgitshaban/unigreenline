<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->company().' Warehouse',
            'city' => fake()->city(),
            'governorate' => fake()->state(),
            'address' => fake()->address(),
            'manager_id' => null,
            'manager_name' => null,
            'temperature' => 'ambient',
            'capacity' => fake()->numberBetween(1000, 10000),
            'phone' => fake()->phoneNumber(),
            'status' => 'active',
        ];
    }

    public function coldStorage(): static
    {
        return $this->state(fn (array $attributes) => [
            'temperature' => 'refrigerated 2-8°C',
        ]);
    }
}

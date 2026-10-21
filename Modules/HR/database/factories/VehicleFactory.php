<?php

namespace Modules\HR\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\HR\Models\Vehicle;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'license_plate' => strtoupper(fake()->unique()->bothify('??-####')),
            'brand' => 'Toyota',
            'model' => 'Hilux',
            'vehicle_type' => 'truck',
            'fuel_type' => 'diesel',
            'odometer' => 1000,
            'status' => 'active',
        ];
    }
}

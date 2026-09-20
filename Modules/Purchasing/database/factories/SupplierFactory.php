<?php

namespace Modules\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Purchasing\Models\Supplier;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->company(),
            'country' => 'Egypt',
            'city' => fake()->city(),
            'contact' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'pay_terms' => 'Net 30',
            'currency' => 'EGP',
            'balance' => 0,
            'rating' => fake()->numberBetween(1, 5),
            'status' => 'active',
        ];
    }
}

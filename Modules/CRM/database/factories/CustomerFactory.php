<?php

namespace Modules\CRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sales_rep_id' => null,
            'name' => fake()->company(),
            'type' => fake()->randomElement(['Clinic', 'Farm', 'Poultry', 'Distributor', 'Retailer', 'Other']),
            'classification' => fake()->randomElement(['A+', 'A', 'B', 'C']),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'governorate' => fake()->state(),
            'city' => fake()->city(),
            'address' => fake()->address(),
            'credit_limit' => 50000,
            'pay_terms' => 'Net 30',
            'balance' => 0,
            'status' => 'active',
        ];
    }
}

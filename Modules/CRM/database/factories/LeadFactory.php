<?php

namespace Modules\CRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Lead;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'assigned_to' => null,
            'name' => fake()->company(),
            'type' => fake()->randomElement(['Clinic', 'Farm', 'Poultry', 'Distributor', 'Retailer', 'Other']),
            'contact' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'source' => fake()->randomElement(['Referral', 'Website', 'Cold Call', 'Exhibition']),
            'status' => 'new',
            'value' => fake()->randomFloat(2, 5000, 100000),
            'notes' => null,
        ];
    }
}

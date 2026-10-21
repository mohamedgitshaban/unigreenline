<?php

namespace Modules\CRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerCall;

/**
 * @extends Factory<CustomerCall>
 */
class CustomerCallFactory extends Factory
{
    protected $model = CustomerCall::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => Customer::factory(),
            'rep_id' => null,
            'called_at' => now(),
            'direction' => 'outbound',
            'outcome' => 'answered',
            'duration_seconds' => 120,
            'subject' => null,
            'message' => fake()->sentence(),
            'next_follow_up' => null,
        ];
    }
}

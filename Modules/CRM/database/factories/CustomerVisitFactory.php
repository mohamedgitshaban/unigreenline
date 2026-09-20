<?php

namespace Modules\CRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerVisit;

/**
 * @extends Factory<CustomerVisit>
 */
class CustomerVisitFactory extends Factory
{
    protected $model = CustomerVisit::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => Customer::factory(),
            'rep_id' => null,
            'visit_date' => now()->toDateString(),
            'type' => 'Routine',
            'outcome' => 'positive',
            'notes' => null,
            'next_visit' => null,
        ];
    }
}

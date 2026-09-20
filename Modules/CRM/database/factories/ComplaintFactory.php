<?php

namespace Modules\CRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Complaint;
use Modules\CRM\Models\Customer;

/**
 * @extends Factory<Complaint>
 */
class ComplaintFactory extends Factory
{
    protected $model = Complaint::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => Customer::factory(),
            'product_id' => null,
            'assigned_to' => null,
            'batch_no' => null,
            'type' => 'Product Quality',
            'description' => fake()->sentence(),
            'resolution' => null,
            'priority' => 'medium',
            'status' => 'investigating',
            'complaint_date' => now()->toDateString(),
            'resolved_date' => null,
        ];
    }
}

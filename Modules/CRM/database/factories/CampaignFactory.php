<?php

namespace Modules\CRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Campaign;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'created_by' => null,
            'name' => fake()->catchPhrase(),
            'type' => 'Discount',
            'target' => 'All Customers',
            'discount' => 10,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'description' => null,
            'status' => 'active',
            'reach' => 0,
            'revenue' => 0,
        ];
    }
}

<?php

namespace Modules\Recap\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Warehouse;
use Modules\Recap\Models\Recap;

/**
 * @extends Factory<Recap>
 */
class RecapFactory extends Factory
{
    protected $model = Recap::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'reason' => 'expired',
            'status' => 'draft',
            'recap_date' => now()->toDateString(),
            'notes' => fake()->sentence(),
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved', 'approved_at' => now()]);
    }
}

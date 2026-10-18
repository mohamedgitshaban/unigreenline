<?php

namespace Modules\Manufacturing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\WorkCenter;
use Modules\Manufacturing\Models\WorkOrder;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'production_order_id' => ProductionOrder::factory(),
            'number' => 'WO'.fake()->unique()->numerify('######'),
            'sequence' => 10,
            'name' => fake()->words(2, true),
            'work_center_id' => WorkCenter::factory(),
            'status' => 'pending',
            'qty_planned' => 1,
            'planned_minutes' => 60,
        ];
    }
}

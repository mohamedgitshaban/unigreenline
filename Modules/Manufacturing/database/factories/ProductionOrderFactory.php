<?php

namespace Modules\Manufacturing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ProductionOrder;

/**
 * @extends Factory<ProductionOrder>
 */
class ProductionOrderFactory extends Factory
{
    protected $model = ProductionOrder::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'bom_id' => Bom::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => 'draft',
            'runs' => 1,
            'output_qty_cartons' => 1,
            'production_date' => now()->toDateString(),
            'batch_no' => 'MO-'.fake()->unique()->numerify('######'),
            'exp_date' => now()->addYear()->toDateString(),
            'conversion_cost' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => 'completed', 'completed_at' => now()]);
    }
}

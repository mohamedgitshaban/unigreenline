<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'product_id' => Product::factory(),
            'batch_id' => null,
            'batch_no' => 'B-'.fake()->unique()->numerify('####'),
            'qty_cartons' => 10,
            'unit_cost' => 100,
            'type' => 'goods_receipt',
            'occurred_at' => now(),
        ];
    }
}

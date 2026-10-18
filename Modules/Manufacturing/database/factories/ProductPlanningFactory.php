<?php

namespace Modules\Manufacturing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Product;
use Modules\Manufacturing\Models\ProductPlanning;

/**
 * @extends Factory<ProductPlanning>
 */
class ProductPlanningFactory extends Factory
{
    protected $model = ProductPlanning::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_id' => Product::factory(),
            'lead_time_days' => 0,
            'lot_rule' => 'exact',
            'make_to_order' => false,
        ];
    }
}

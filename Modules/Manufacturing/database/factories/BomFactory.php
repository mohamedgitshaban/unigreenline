<?php

namespace Modules\Manufacturing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Product;
use Modules\Manufacturing\Models\Bom;

/**
 * @extends Factory<Bom>
 */
class BomFactory extends Factory
{
    protected $model = Bom::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_id' => Product::factory(),
            'code' => 'BOM'.fake()->unique()->numerify('######'),
            'name' => fake()->unique()->words(3, true),
            'output_qty_cartons' => 1,
            'conversion_cost' => 0,
            'active' => true,
        ];
    }
}

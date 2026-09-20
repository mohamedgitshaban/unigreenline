<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'category_id' => ProductCategory::factory(),
            'supplier_id' => null,
            'name' => fake()->unique()->words(3, true),
            'sku' => fake()->unique()->bothify('PRD-#####'),
            'brand' => fake()->company(),
            'pack_unit' => 'Vial 100ml',
            'carton_qty' => fake()->numberBetween(10, 100),
            'pack_cost_price' => fake()->randomFloat(2, 5, 50),
            'pack_selling_price' => fake()->randomFloat(2, 10, 80),
            'discount_pct' => 0,
            'tax_pct' => 14,
            'min_stock_cartons' => 10,
            'reorder_level' => 20,
            'active' => true,
        ];
    }
}

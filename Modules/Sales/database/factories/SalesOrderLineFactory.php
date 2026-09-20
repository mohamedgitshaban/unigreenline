<?php

namespace Modules\Sales\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Product;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

/**
 * @extends Factory<SalesOrderLine>
 */
class SalesOrderLineFactory extends Factory
{
    protected $model = SalesOrderLine::class;

    public function definition(): array
    {
        return [
            'so_id' => SalesOrder::factory(),
            'product_id' => Product::factory(),
            'batch_no' => null,
            'qty' => 10,
            'unit' => 'Carton',
            'unit_price' => 65.00,
            'discount_pct' => 0,
            'free_qty' => 0,
            'subtotal' => 650.00,
        ];
    }
}

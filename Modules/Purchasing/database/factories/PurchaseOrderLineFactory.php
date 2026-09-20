<?php

namespace Modules\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Product;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseOrderLine;

/**
 * @extends Factory<PurchaseOrderLine>
 */
class PurchaseOrderLineFactory extends Factory
{
    protected $model = PurchaseOrderLine::class;

    public function definition(): array
    {
        return [
            'po_id' => PurchaseOrder::factory(),
            'product_id' => Product::factory(),
            'qty_cartons' => 10,
            'cost_per_carton' => 100,
            'total' => 1000,
        ];
    }
}

<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    protected $model = Transfer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_id' => Product::factory(),
            'from_warehouse_id' => Warehouse::factory(),
            'to_warehouse_id' => Warehouse::factory(),
            'batch_no' => fake()->unique()->bothify('BATCH-#####'),
            'qty_cartons' => 10,
            'transfer_date' => now()->toDateString(),
            'notes' => null,
            'status' => 'completed',
            'created_by' => null,
        ];
    }
}

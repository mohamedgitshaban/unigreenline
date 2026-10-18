<?php

namespace Modules\Manufacturing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Models\MrpDemand;

/**
 * @extends Factory<MrpDemand>
 */
class MrpDemandFactory extends Factory
{
    protected $model = MrpDemand::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'qty_cartons' => 10,
            'due_date' => now()->addDays(14)->toDateString(),
            'kind' => 'manual',
            'status' => 'open',
        ];
    }
}

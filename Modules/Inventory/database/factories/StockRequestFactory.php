<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockRequest;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<StockRequest>
 */
class StockRequestFactory extends Factory
{
    protected $model = StockRequest::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'source' => 'warehouse',
            'from_warehouse_id' => Warehouse::factory(),
            'product_id' => Product::factory(),
            'qty_cartons' => fake()->numberBetween(1, 10),
            'status' => 'requested',
        ];
    }

    public function fromPurchasing(): static
    {
        return $this->state(['source' => 'purchasing', 'from_warehouse_id' => null]);
    }

    public function fromManufacturing(): static
    {
        return $this->state(['source' => 'manufacturing', 'from_warehouse_id' => null]);
    }
}

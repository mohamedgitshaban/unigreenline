<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<InventoryBatch>
 */
class InventoryBatchFactory extends Factory
{
    protected $model = InventoryBatch::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'batch_no' => fake()->unique()->bothify('BATCH-#####'),
            'mfg_date' => fake()->dateTimeBetween('-2 years', '-6 months'),
            'exp_date' => fake()->dateTimeBetween('+6 months', '+2 years'),
            'rcv_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'qty_cartons' => fake()->numberBetween(10, 200),
            'qty_packs' => 0,
            'cost_per_carton' => fake()->randomFloat(2, 50, 500),
        ];
    }

    public function expiringOn(string $date): static
    {
        return $this->state(fn (array $attributes) => ['exp_date' => $date]);
    }

    public function receivedOn(string $date): static
    {
        return $this->state(fn (array $attributes) => ['rcv_date' => $date]);
    }

    public function withQtyCartons(int $qty): static
    {
        return $this->state(fn (array $attributes) => ['qty_cartons' => $qty]);
    }
}

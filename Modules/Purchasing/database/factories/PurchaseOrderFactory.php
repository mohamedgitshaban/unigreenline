<?php

namespace Modules\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'supplier_id' => Supplier::factory(),
            'warehouse_id' => Warehouse::factory(),
            'created_by' => User::factory(),
            'status' => 'draft',
            'stock_added' => false,
            'order_date' => now()->toDateString(),
            'expected_date' => null,
            'received_date' => null,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'notes' => null,
        ];
    }
}

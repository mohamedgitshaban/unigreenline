<?php

namespace Modules\Sales\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Models\SalesOrder;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => Customer::factory(),
            'sales_rep_id' => User::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => 'draft',
            'pay_type' => 'cash',
            'grace_period' => 0,
            'due_date' => null,
            'invoice_discount' => 0,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'stock_deducted' => false,
            'order_date' => now()->toDateString(),
        ];
    }
}

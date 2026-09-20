<?php

namespace Modules\Sales\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\SalesOrder;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'so_id' => SalesOrder::factory(),
            'invoice_id' => null,
            'customer_id' => Customer::factory(),
            'driver' => null,
            'delivery_date' => null,
            'status' => 'pending',
            'delivered_at' => null,
            'notes' => null,
        ];
    }
}

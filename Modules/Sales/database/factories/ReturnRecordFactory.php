<?php

namespace Modules\Sales\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Sales\Models\ReturnRecord;

/**
 * @extends Factory<ReturnRecord>
 */
class ReturnRecordFactory extends Factory
{
    protected $model = ReturnRecord::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'invoice_id' => null,
            'customer_id' => null,
            'supplier_id' => null,
            'product_id' => null,
            'warehouse_id' => null,
            'batch_no' => null,
            'type' => 'Sales Return',
            'qty' => 5,
            'unit' => 'Carton',
            'amount' => 100,
            'restocked' => false,
            'reason' => null,
            'status' => 'completed',
            'return_date' => now()->toDateString(),
        ];
    }
}

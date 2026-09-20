<?php

namespace Modules\Sales\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Invoice;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $total = 1000;

        return [
            'tenant_id' => Tenant::factory(),
            'so_id' => null,
            'customer_id' => Customer::factory(),
            'issued_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 900,
            'tax_amount' => 100,
            'total' => $total,
            'paid' => 0,
            'balance' => $total,
            'status' => 'outstanding',
        ];
    }
}

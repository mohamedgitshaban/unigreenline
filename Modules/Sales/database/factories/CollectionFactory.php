<?php

namespace Modules\Sales\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Invoice;

/**
 * @extends Factory<Collection>
 */
class CollectionFactory extends Factory
{
    protected $model = Collection::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'invoice_id' => Invoice::factory(),
            'customer_id' => Customer::factory(),
            'collected_by' => null,
            'amount' => 100,
            'method' => 'Cash',
            'reference' => null,
            'payment_date' => now()->toDateString(),
            'notes' => null,
        ];
    }
}

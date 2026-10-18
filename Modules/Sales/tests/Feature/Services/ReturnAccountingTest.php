<?php

namespace Modules\Sales\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;
use Modules\Sales\Services\ProcessReturnService;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

class ReturnAccountingTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->ensureLedgerAccounts($this->tenant);
        $this->product = Product::factory()->recycle($this->tenant)->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
    }

    public function test_a_restocked_sales_return_reverses_the_revenue_the_customer_balance_and_the_cost_of_the_goods(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['balance' => 500]);
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->warehouse])->create(['batch_no' => 'B1', 'qty_cartons' => 10, 'cost_per_carton' => '30.00']);

        $this->process(['type' => 'Sales Return', 'customer_id' => $customer->id, 'batch_no' => 'B1', 'qty' => 4, 'amount' => 200, 'restocked' => true]);

        $this->assertSame('-200.00', $this->balance('4100'));   // revenue reduced
        $this->assertSame('-200.00', $this->balance('1200'));   // receivable reduced
        $this->assertSame('120.00', $this->balance('1300'));    // 4 × 30 back in stock
        $this->assertSame('-120.00', $this->balance('5100'));   // cost of goods sold reversed
        $this->assertSame('300.00', $customer->fresh()->balance);
    }

    public function test_a_return_that_is_not_restocked_credits_the_customer_but_puts_nothing_back_in_inventory(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['balance' => 100]);

        $this->process(['type' => 'Damaged', 'customer_id' => $customer->id, 'qty' => 1, 'amount' => 150, 'restocked' => false]);

        $this->assertSame('-150.00', $this->balance('4100'));
        $this->assertSame('0.00', $this->balance('1300'));
        $this->assertSame('0.00', $customer->fresh()->balance); // floored at zero
    }

    public function test_a_purchase_return_reduces_what_is_owed_to_the_supplier_and_the_inventory(): void
    {
        $supplier = Supplier::factory()->recycle($this->tenant)->create(['balance' => -1000]);
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->warehouse])->create(['batch_no' => 'B2', 'qty_cartons' => 10]);

        $this->process(['type' => 'Purchase Return', 'supplier_id' => $supplier->id, 'batch_no' => 'B2', 'qty' => 3, 'amount' => 300]);

        $this->assertSame('-300.00', $this->balance('2100'));   // payable reduced (debit on a liability)
        $this->assertSame('-300.00', $this->balance('1300'));   // inventory reduced
        $this->assertSame('-700.00', $supplier->fresh()->balance);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function process(array $data): void
    {
        $this->app->make(ProcessReturnService::class)->process($data + [
            'tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'unit' => 'Carton',
        ]);
    }

    private function balance(string $code): string
    {
        return Account::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail()->balance;
    }
}

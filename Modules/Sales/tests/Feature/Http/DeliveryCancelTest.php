<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * Cancelling a delivery undoes the whole sale: stock back in its batches, cost of goods sold and invoice reversed.
 */
class DeliveryCancelTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Customer $customer;

    private Product $product;

    private InventoryBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->customer = Customer::factory()->recycle($this->tenant)->create(['credit_limit' => 100000, 'balance' => 0]);
        $this->product = Product::factory()->recycle($this->tenant)->create(['tax_pct' => 10]);
        $this->batch = InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->warehouse])->withQtyCartons(10)->create(['cost_per_carton' => 50]);
        $this->ensureLedgerAccounts($this->tenant);
    }

    public function test_cancelling_puts_the_stock_back_and_reverses_the_invoice_and_the_cost(): void
    {
        $delivery = $this->deliveredSale();
        $this->assertSame(6, $this->batch->fresh()->qty_cartons);
        $this->assertSame('440.00', $this->customer->fresh()->balance);

        $this->actingAsRole('Warehouse Manager');

        $this->postJson("/api/v1/deliveries/{$delivery['id']}/cancel", ['reason' => 'Customer refused the goods'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10, $this->batch->fresh()->qty_cartons);
        $this->assertSame('0.00', $this->customer->fresh()->balance);
        $this->assertDatabaseHas('invoices', ['customer_id' => $this->customer->id, 'status' => 'void', 'balance' => '0.00']);
        $this->assertDatabaseHas('sales_orders', ['customer_id' => $this->customer->id, 'status' => 'cancelled', 'stock_deducted' => 0]);
        $this->assertDatabaseHas('stock_movements', ['batch_id' => $this->batch->id, 'type' => 'sale_cancel', 'qty_cartons' => 4]);
        $this->assertSame('0.00', $this->balanceOf('1200'));
        $this->assertSame('0.00', $this->balanceOf('4100'));
        $this->assertSame('0.00', $this->balanceOf('5100'));
        $this->assertSame('0.00', $this->balanceOf('1300'));
    }

    public function test_an_accountant_may_cancel_but_a_sales_rep_may_not(): void
    {
        $delivery = $this->deliveredSale();

        $this->actingAsRole('Sales Rep');
        $this->postJson("/api/v1/deliveries/{$delivery['id']}/cancel")->assertForbidden();

        $this->actingAsRole('Accountant');
        $this->postJson("/api/v1/deliveries/{$delivery['id']}/cancel")->assertOk();
    }

    public function test_a_delivery_with_money_collected_against_it_is_refused_and_nothing_changes(): void
    {
        $delivery = $this->deliveredSale();
        $this->actingAsRole('Accountant');
        $invoiceId = $delivery['invoice_id'];
        $this->postJson('/api/v1/collections', ['invoice_id' => $invoiceId, 'amount' => 100, 'method' => 'Cash'])->assertCreated();

        $this->postJson("/api/v1/deliveries/{$delivery['id']}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Money was collected against this order\'s invoice. Reverse the collections first.');

        $this->assertSame(6, $this->batch->fresh()->qty_cartons);
        $this->assertDatabaseHas('deliveries', ['id' => $delivery['id'], 'status' => 'delivered']);
    }

    public function test_a_delivery_with_goods_already_returned_is_refused(): void
    {
        $delivery = $this->deliveredSale();
        $this->actingAsRole('Administrator');
        $line = $this->getJson("/api/v1/invoices/{$delivery['invoice_id']}/lines")->json('data.0.id');
        $this->postJson("/api/v1/invoices/{$delivery['invoice_id']}/returns", ['type' => 'Sales Return', 'restocked' => true, 'lines' => [['line_id' => $line, 'qty' => 1]]])->assertCreated();

        $this->postJson("/api/v1/deliveries/{$delivery['id']}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Goods were returned against this order\'s invoice, so it can no longer be cancelled.');
    }

    public function test_a_delivery_cannot_be_cancelled_twice(): void
    {
        $delivery = $this->deliveredSale();
        $this->actingAsRole('Warehouse Manager');

        $this->postJson("/api/v1/deliveries/{$delivery['id']}/cancel")->assertOk();
        $this->postJson("/api/v1/deliveries/{$delivery['id']}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This delivery is already cancelled.');

        $this->assertSame(10, $this->batch->fresh()->qty_cartons);
    }

    public function test_an_invoice_whose_goods_left_the_warehouse_cannot_simply_be_voided(): void
    {
        $delivery = $this->deliveredSale();
        $this->actingAsRole('Administrator');

        $this->postJson("/api/v1/invoices/{$delivery['invoice_id']}/void")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The goods for this invoice have left the warehouse. Cancel its delivery instead, which also returns the stock.');

        $this->assertDatabaseHas('invoices', ['id' => $delivery['invoice_id'], 'status' => 'outstanding']);
    }

    public function test_another_companys_delivery_is_not_found(): void
    {
        $delivery = $this->deliveredSale();
        $other = Tenant::factory()->create();
        $user = User::factory()->recycle($other)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/deliveries/{$delivery['id']}/cancel")->assertNotFound();
    }

    /**
     * A credit sale of 4 cartons at 100 + 10% VAT (440), paid for in stock at cost 50 a carton.
     *
     * @return array{id: string, invoice_id: string}
     */
    private function deliveredSale(): array
    {
        $this->actingAsRole('Sales Manager');

        $order = $this->postJson('/api/v1/sales-orders/deliver', [
            'customer_id' => $this->customer->id,
            'pay_type' => 'credit',
            'lines' => [['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 4, 'unit' => 'Carton', 'unit_price' => 100]],
        ])->assertCreated()->json('data');

        return ['id' => $order['delivery']['id'], 'invoice_id' => $this->getJson("/api/v1/sales-orders/{$order['id']}/invoices")->json('data.0.id')];
    }

    private function balanceOf(string $code): string
    {
        return Account::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->first()->balance;
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        $this->warehouse->users()->attach($user);
        Sanctum::actingAs($user);

        return $user;
    }
}

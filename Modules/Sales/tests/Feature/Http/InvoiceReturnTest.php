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
use Modules\Sales\Models\Invoice;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * Returns made from an invoice: the lines ticked, priced from the invoice itself, applied to the invoice.
 */
class InvoiceReturnTest extends TestCase
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

        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);
    }

    public function test_returned_lines_credit_the_invoice_price_plus_vat_and_come_back_into_stock(): void
    {
        [$invoiceId, $lineId] = $this->sale(discount: 0);

        $this->postJson("/api/v1/invoices/{$invoiceId}/returns", ['type' => 'Sales Return', 'restocked' => true, 'reason' => 'Wrong pack', 'lines' => [['line_id' => $lineId, 'qty' => 1]]])
            ->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', '100.00')
            ->assertJsonPath('data.0.tax_amount', '10.00')
            ->assertJsonPath('data.0.so_line_id', $lineId)
            ->assertJsonPath('data.0.number', 'RET-000001');

        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'returned_total' => '110.00', 'balance' => '330.00', 'status' => 'outstanding']);
        $this->assertSame('330.00', $this->customer->fresh()->balance);
        $this->assertSame(7, $this->batch->fresh()->qty_cartons);
        $this->assertDatabaseHas('stock_movements', ['batch_id' => $this->batch->id, 'type' => 'sale_return', 'qty_cartons' => 1]);
        $this->assertSame('300.00', $this->balanceOf('4100'));
        $this->assertSame('30.00', $this->balanceOf('2300'));
        $this->assertSame('330.00', $this->balanceOf('1200'));
        $this->assertSame('150.00', $this->balanceOf('5100'));
    }

    public function test_the_invoice_discount_is_shared_out_over_the_returned_units(): void
    {
        [$invoiceId, $lineId] = $this->sale(discount: 40);

        $this->postJson("/api/v1/invoices/{$invoiceId}/returns", ['type' => 'Sales Return', 'restocked' => true, 'lines' => [['line_id' => $lineId, 'qty' => 2]]])
            ->assertCreated()
            ->assertJsonPath('data.0.amount', '180.00')
            ->assertJsonPath('data.0.tax_amount', '20.00');

        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'returned_total' => '200.00', 'balance' => '200.00']);
    }

    public function test_a_line_cannot_be_returned_beyond_what_was_billed_less_what_came_back_already(): void
    {
        [$invoiceId, $lineId] = $this->sale(discount: 0);
        $this->postJson("/api/v1/invoices/{$invoiceId}/returns", ['type' => 'Sales Return', 'restocked' => true, 'lines' => [['line_id' => $lineId, 'qty' => 3]]])->assertCreated();

        $this->postJson("/api/v1/invoices/{$invoiceId}/returns", ['type' => 'Sales Return', 'restocked' => true, 'lines' => [['line_id' => $lineId, 'qty' => 2]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.qty' => 'Only 1 of this line can still be returned.']);

        $this->assertDatabaseCount('returns', 1);
    }

    public function test_damaged_goods_that_are_not_restocked_leave_the_stock_alone(): void
    {
        [$invoiceId, $lineId] = $this->sale(discount: 0);

        $this->postJson("/api/v1/invoices/{$invoiceId}/returns", ['type' => 'Damaged', 'restocked' => false, 'lines' => [['line_id' => $lineId, 'qty' => 1]]])->assertCreated();

        $this->assertSame(6, $this->batch->fresh()->qty_cartons);
        $this->assertDatabaseMissing('stock_movements', ['type' => 'sale_return']);
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'returned_total' => '110.00']);
    }

    public function test_a_line_that_belongs_to_another_invoice_is_refused(): void
    {
        [$invoiceId] = $this->sale(discount: 0);
        [, $otherLineId] = $this->sale(discount: 0);

        $this->postJson("/api/v1/invoices/{$invoiceId}/returns", ['type' => 'Sales Return', 'lines' => [['line_id' => $otherLineId, 'qty' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.line_id' => 'This line does not belong to the invoice.']);
    }

    public function test_an_imported_invoice_has_no_lines_to_return_against(): void
    {
        $invoice = Invoice::factory()->recycle([$this->tenant, $this->customer])->create();

        $this->postJson("/api/v1/invoices/{$invoice->id}/returns", ['type' => 'Sales Return', 'lines' => [['line_id' => 'any', 'qty' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');
    }

    public function test_an_invoices_returns_are_listed(): void
    {
        [$invoiceId, $lineId] = $this->sale(discount: 0);
        $this->postJson("/api/v1/invoices/{$invoiceId}/returns", ['type' => 'Sales Return', 'restocked' => true, 'lines' => [['line_id' => $lineId, 'qty' => 1]]])->assertCreated();

        $this->getJson("/api/v1/invoices/{$invoiceId}/returns")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.so_line_id', $lineId);
    }

    public function test_lines_and_a_valid_type_are_required(): void
    {
        [$invoiceId] = $this->sale(discount: 0);

        $this->postJson("/api/v1/invoices/{$invoiceId}/returns", ['type' => 'Purchase Return'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'lines']);
    }

    public function test_a_role_without_return_rights_is_forbidden(): void
    {
        [$invoiceId, $lineId] = $this->sale(discount: 0);
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/invoices/{$invoiceId}/returns", ['type' => 'Sales Return', 'lines' => [['line_id' => $lineId, 'qty' => 1]]])->assertForbidden();
    }

    /**
     * A credit sale of 4 cartons at 100 plus 10% VAT, with an optional flat invoice discount.
     *
     * @return array{0: string, 1: string} The invoice id and its one line's id.
     */
    private function sale(float $discount): array
    {
        $order = $this->postJson('/api/v1/sales-orders/deliver', [
            'customer_id' => $this->customer->id,
            'pay_type' => 'credit',
            'invoice_discount' => $discount,
            'lines' => [['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 4, 'unit' => 'Carton', 'unit_price' => 100]],
        ])->assertCreated()->json('data');

        $invoiceId = $this->getJson("/api/v1/sales-orders/{$order['id']}/invoices")->json('data.0.id');

        return [$invoiceId, $this->getJson("/api/v1/invoices/{$invoiceId}/lines")->json('data.0.id')];
    }

    private function balanceOf(string $code): string
    {
        return Account::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->first()->balance;
    }
}

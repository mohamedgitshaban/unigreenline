<?php

namespace Modules\Sales\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\Sales\Exceptions\InvalidCollectionAmountException;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Services\CreateCollectionService;
use Tests\TestCase;

class CreateCollectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_payment_marks_the_invoice_partial(): void
    {
        [$tenant, $customer, $invoice] = $this->arrange(['total' => 1000, 'balance' => 1000, 'paid' => 0]);

        $collection = $this->service()->create($this->data($invoice, 400));

        $this->assertSame('400.00', $collection->amount);
        $this->assertSame('400.00', $invoice->fresh()->paid);
        $this->assertSame('600.00', $invoice->fresh()->balance);
        $this->assertSame('partial', $invoice->fresh()->status);
    }

    public function test_full_payment_marks_the_invoice_paid(): void
    {
        [$tenant, $customer, $invoice] = $this->arrange(['total' => 1000, 'balance' => 1000, 'paid' => 0]);

        $this->service()->create($this->data($invoice, 1000));

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->balance);
    }

    public function test_amount_exceeding_the_remaining_balance_is_rejected(): void
    {
        [$tenant, $customer, $invoice] = $this->arrange(['total' => 1000, 'balance' => 300, 'paid' => 700]);

        $this->expectException(InvalidCollectionAmountException::class);

        $this->service()->create($this->data($invoice, 301));
    }

    public function test_zero_amount_is_rejected(): void
    {
        [$tenant, $customer, $invoice] = $this->arrange();

        $this->expectException(InvalidCollectionAmountException::class);

        $this->service()->create($this->data($invoice, 0));
    }

    public function test_already_paid_invoice_rejects_further_collections(): void
    {
        [$tenant, $customer, $invoice] = $this->arrange(['total' => 1000, 'balance' => 0, 'paid' => 1000, 'status' => 'paid']);

        $this->expectException(InvalidCollectionAmountException::class);

        $this->service()->create($this->data($invoice, 1));
    }

    public function test_decrements_the_customers_balance_floored_at_zero(): void
    {
        [$tenant, $customer, $invoice] = $this->arrange(['total' => 1000, 'balance' => 1000, 'paid' => 0]);
        $customer->forceFill(['balance' => 300])->save();

        // Customer AR balance (300) is lower than the collected amount (1000)
        // — e.g. it was adjusted elsewhere — balance must floor at 0, not go negative.
        $this->service()->create($this->data($invoice, 1000));

        $this->assertSame('0.00', $customer->fresh()->balance);
    }

    public function test_posts_cash_account_for_cash_method(): void
    {
        [$tenant, $customer, $invoice] = $this->arrange(['total' => 1000, 'balance' => 1000, 'paid' => 0]);
        $cash = Account::query()->where('tenant_id', $tenant->id)->where('code', '1110')->firstOrFail();
        $ar = Account::query()->where('tenant_id', $tenant->id)->where('code', '1200')->firstOrFail();

        $this->service()->create($this->data($invoice, 400, method: 'Cash'));

        $this->assertSame('400.00', $cash->fresh()->balance);
        $this->assertSame('-400.00', $ar->fresh()->balance);
    }

    public function test_posts_bank_account_for_non_cash_method(): void
    {
        [$tenant, $customer, $invoice] = $this->arrange(['total' => 1000, 'balance' => 1000, 'paid' => 0]);
        $bank = Account::query()->where('tenant_id', $tenant->id)->where('code', '1120')->firstOrFail();
        $ar = Account::query()->where('tenant_id', $tenant->id)->where('code', '1200')->firstOrFail();

        $this->service()->create($this->data($invoice, 400, method: 'Bank Transfer'));

        $this->assertSame('400.00', $bank->fresh()->balance);
        $this->assertSame('-400.00', $ar->fresh()->balance);
    }

    public function test_records_an_audit_log_entry(): void
    {
        [$tenant, $customer, $invoice] = $this->arrange(['total' => 1000, 'balance' => 1000, 'paid' => 0]);

        $collection = $this->service()->create($this->data($invoice, 400));

        $this->assertDatabaseHas('audit_log', [
            'module' => 'sales',
            'entity_type' => 'Collection',
            'entity_id' => $collection->id,
            'operation' => 'INSERT',
        ]);
    }

    /**
     * @return array{0: Tenant, 1: Customer, 2: Invoice}
     */
    private function arrange(array $invoiceAttributes = []): array
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create(['balance' => $invoiceAttributes['balance'] ?? 0]);
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create($invoiceAttributes);

        // Every successful collection posts a journal entry — seed the
        // accounts it always needs so tests that aren't specifically about
        // journal posting don't have to think about it.
        Account::factory()->recycle($tenant)->create(['code' => '1110', 'type' => 'Asset', 'balance' => 0]);
        Account::factory()->recycle($tenant)->create(['code' => '1120', 'type' => 'Asset', 'balance' => 0]);
        Account::factory()->recycle($tenant)->create(['code' => '1200', 'type' => 'Asset', 'balance' => 0]);

        return [$tenant, $customer, $invoice];
    }

    private function data(Invoice $invoice, float $amount, string $method = 'Cash'): array
    {
        return [
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => $method,
        ];
    }

    private function service(): CreateCollectionService
    {
        return $this->app->make(CreateCollectionService::class);
    }
}

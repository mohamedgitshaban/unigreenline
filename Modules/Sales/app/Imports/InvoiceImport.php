<?php

namespace Modules\Sales\Imports;

use Illuminate\Support\Carbon;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Exceptions\ImportRowException;
use Modules\Core\Imports\ImportDefinition;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Invoice;

/**
 * Brings in historical / opening invoices. Each row creates an invoice for
 * an existing customer (matched by name) and posts the same journal entry a
 * live invoice would (Dr Receivable / Cr Sales Revenue + VAT Payable); any
 * already-paid amount is posted as a cash receipt, and the customer's
 * balance rises by what is still owed. Invoices have no natural key, so
 * re-importing the same file creates duplicates and `update_existing` has no
 * effect here.
 */
class InvoiceImport extends ImportDefinition
{
    public function columns(): array
    {
        return ['Customer', 'Issued Date', 'Due Date', 'Subtotal', 'Tax Amount', 'Paid'];
    }

    public function requiredColumns(): array
    {
        return ['Customer', 'Issued Date', 'Subtotal'];
    }

    public function exampleRow(): array
    {
        return ['Nile Vet Clinic', '2026-01-15', '2026-02-14', 1000, 140, 0];
    }

    public function dateColumns(): array
    {
        return ['issued_date', 'due_date'];
    }

    public function rules(): array
    {
        return [
            'customer' => ['required', 'string'],
            'issued_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issued_date'],
            'subtotal' => ['required', 'numeric', 'min:0.01'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'paid' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function persist(array $row, bool $updateExisting): string
    {
        $customer = Customer::query()
            ->where('tenant_id', $this->user->tenant_id)
            ->where('name', $row['customer'])
            ->first()
            ?? throw new ImportRowException('customer', "No customer named \"{$row['customer']}\".");

        if (! app(DataScope::class)->canSee($this->user, $customer->sales_rep_id)) {
            throw new ImportRowException('customer', 'You can only import invoices for your own customers or those of your team.');
        }

        $subtotal = round((float) $row['subtotal'], 2);
        $tax = round((float) ($row['tax_amount'] ?? 0), 2);
        $total = round($subtotal + $tax, 2);
        $paid = round((float) ($row['paid'] ?? 0), 2);

        if ($paid > $total) {
            throw new ImportRowException('paid', 'Paid cannot exceed the invoice total.');
        }

        $balance = round($total - $paid, 2);
        $dueDate = $row['due_date'] ?? null;

        $invoice = Invoice::create([
            'tenant_id' => $this->user->tenant_id,
            'customer_id' => $customer->id,
            'issued_date' => $row['issued_date'],
            'due_date' => $dueDate,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => $total,
            'paid' => $paid,
            'balance' => $balance,
            'status' => match (true) {
                $balance === 0.0 => 'paid',
                $dueDate !== null && Carbon::parse($dueDate)->lt(today()) => 'overdue',
                $paid > 0 => 'partial',
                default => 'outstanding',
            },
        ]);

        $journal = app(JournalPostingService::class);

        $journal->post(
            $this->user->tenant_id,
            $invoice->id,
            "Imported invoice {$invoice->id}",
            array_values(array_filter([
                ['account_code' => '1200', 'debit' => $total],
                ['account_code' => '4100', 'credit' => $subtotal],
                ['account_code' => '2300', 'credit' => $tax],
            ], fn (array $line) => ($line['debit'] ?? 0) > 0 || ($line['credit'] ?? 0) > 0)),
            $this->user->id,
            $invoice->issued_date,
        );

        if ($paid > 0) {
            $journal->post(
                $this->user->tenant_id,
                $invoice->id,
                "Imported payment on invoice {$invoice->id}",
                [['account_code' => '1110', 'debit' => $paid], ['account_code' => '1200', 'credit' => $paid]],
                $this->user->id,
                $invoice->issued_date,
            );
        }

        if ($balance > 0) {
            $customer->increment('balance', $balance);
        }

        return 'created';
    }

    public function module(): string
    {
        return 'sales';
    }

    public function entity(): string
    {
        return 'Invoice';
    }
}

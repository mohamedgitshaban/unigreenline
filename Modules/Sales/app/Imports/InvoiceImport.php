<?php

namespace Modules\Sales\Imports;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Exceptions\ImportRowException;
use Modules\Core\Imports\ImportDefinition;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\Product;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\ReturnRecord;
use Modules\Sales\Services\ProcessReturnService;

/**
 * Brings in historical / opening invoices. Each row creates an invoice for
 * an existing customer (matched by name) and posts the same journal entry a
 * live invoice would (Dr Receivable / Cr Sales Revenue + VAT Payable); any
 * already-paid amount is posted as a cash receipt, and the customer's
 * balance rises by what is still owed. Invoices have no natural key, so
 * re-importing the same file creates duplicates and `update_existing` has no
 * effect here.
 *
 * Also reads the company's "Invoice Data" sheet, which has one row per product line
 * (Invoice Number, Date, Customer Code, Product Code, Units, Price, Discount %) and mixes two kinds of
 * document in one numbering:
 *  - a sale (SI-…): the lines add up to one invoice, which keeps the company's own number, so importing
 *    the sheet again skips what is already there. The customer is found by its code. Only the invoice's
 *    total is kept, not its lines (like every imported invoice, it can't be returned against).
 *  - a return (RT-…, negative prices): one return per line, posted like a return recorded without an
 *    invoice (credit note against the customer's balance, no stock movement).
 * A document whose lines add up to nothing (everything given free) has nothing to bill and is skipped.
 */
class InvoiceImport extends ImportDefinition
{
    public function sheetNames(): array
    {
        return ['Invoice Data 2024, 25, 26', 'Invoice Data', 'Invoices'];
    }

    public function aliases(): array
    {
        return [
            'invoice_number' => 'number',
            'date' => 'issued_date',
            'customer_name' => 'customer',
        ];
    }

    public function prepare(array $rows, array $headings): array
    {
        $this->workbookFormat = in_array('invoice_number', $headings, true);

        if (! $this->workbookFormat) {
            return $rows;
        }

        $documents = [];

        foreach ($rows as $sheetRow => $row) {
            $number = $row['number'] ?? null;

            // The sheet's formulas run past its last invoice, leaving rows with nothing but a formula in them.
            if ($number === null) {
                continue;
            }

            $document = $documents[$number] ?? [
                'row' => $sheetRow,
                'number' => $number,
                'customer_code' => $row['customer_code'] ?? null,
                'customer' => $row['customer'] ?? null,
                'issued_date' => $row['issued_date'] ?? null,
                'conflict' => false,
                'lines' => [],
            ];

            $document['conflict'] = $document['conflict']
                || ($row['customer_code'] ?? null) !== $document['customer_code']
                || ($row['issued_date'] ?? null) !== $document['issued_date'];

            // A line with no quantity or price is worth nothing, as in the sheet's own totals.
            $document['lines'][] = [
                'product_code' => $row['product_code'] ?? null,
                'units' => $row['units'] ?? '0',
                'price' => $row['price'] ?? '0',
                'discount' => $row['discount'] ?? '0',
            ];

            $documents[$number] = $document;
        }

        $prepared = [];

        foreach ($documents as $document) {
            $total = round(array_sum(array_map($this->lineTotal(...), $document['lines'])), 2);

            $prepared[$document['row']] = [
                'number' => $document['number'],
                'customer_code' => $document['customer_code'],
                'customer' => $document['customer'],
                'issued_date' => $document['issued_date'],
                'subtotal' => (string) abs($total),
                'kind' => match (true) {
                    $total < 0 => 'return',
                    $total === 0.0 => 'free',
                    default => 'invoice',
                },
                'conflict' => $document['conflict'],
                'lines' => $document['lines'],
            ];
        }

        return $prepared;
    }

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
            'number' => [$this->workbookFormat ? 'required' : 'nullable', 'string', 'max:40'],
            'customer_code' => ['nullable', 'string', 'max:40'],
            'customer' => ['required_without:customer_code', 'nullable', 'string'],
            'issued_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issued_date'],
            'subtotal' => ['required', 'numeric', 'min:'.($this->workbookFormat ? '0' : '0.01')],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'paid' => ['nullable', 'numeric', 'min:0'],
            'kind' => ['nullable', Rule::in(['invoice', 'return', 'free'])],
            'conflict' => ['nullable', 'boolean'],
            'lines' => ['nullable', 'array'],
            'lines.*.product_code' => ['nullable', 'string', 'max:100'],
            'lines.*.units' => ['required', 'numeric', 'min:0'],
            'lines.*.price' => ['required', 'numeric'],
            'lines.*.discount' => ['required', 'numeric', 'between:0,1'],
        ];
    }

    public function persist(array $row, bool $updateExisting): string
    {
        $customer = $this->findCustomer($row);

        if (! app(DataScope::class)->canSee($this->user, $customer->sales_rep_id)) {
            throw new ImportRowException('customer', 'You can only import invoices for your own customers or those of your team.');
        }

        if ($row['conflict'] ?? false) {
            throw new ImportRowException('number', "The lines of {$row['number']} don't agree on one customer and date.");
        }

        return match ($row['kind'] ?? 'invoice') {
            'free' => 'skipped',
            'return' => $this->persistReturn($row, $customer),
            default => $this->persistInvoice($row, $customer),
        };
    }

    public function module(): string
    {
        return 'sales';
    }

    public function entity(): string
    {
        return 'Invoice';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function findCustomer(array $row): Customer
    {
        $query = Customer::query()->where('tenant_id', $this->user->tenant_id);

        if (isset($row['customer_code'])) {
            return $query->where('code', $row['customer_code'])->first()
                ?? throw new ImportRowException('customer_code', "No customer with code \"{$row['customer_code']}\".");
        }

        return $query->where('name', $row['customer'])->first()
            ?? throw new ImportRowException('customer', "No customer named \"{$row['customer']}\".");
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function persistInvoice(array $row, Customer $customer): string
    {
        $number = $row['number'] ?? null;

        if ($number !== null && Invoice::query()->where('tenant_id', $this->user->tenant_id)->where('number', $number)->exists()) {
            return 'skipped';
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

        $invoice = new Invoice([
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

        if ($number !== null) {
            $invoice->number = $number;
        }

        $invoice->save();

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

    /**
     * One return per line of the document, each credited at what the customer paid for it.
     *
     * @param  array<string, mixed>  $row
     */
    private function persistReturn(array $row, Customer $customer): string
    {
        $reason = "Imported return {$row['number']}";

        if (ReturnRecord::query()->where('tenant_id', $this->user->tenant_id)->where('reason', $reason)->exists()) {
            return 'skipped';
        }

        $created = false;

        foreach ($row['lines'] as $line) {
            $amount = round(abs($this->lineTotal($line)), 2);

            if ($amount <= 0) {
                continue;
            }

            $code = $line['product_code'] ?? null;
            $product = $code === null ? null : Product::query()->where('tenant_id', $this->user->tenant_id)->where('sku', $code)->first();

            if ($product === null) {
                throw new ImportRowException('product_code', 'No product with code "'.($code ?? '').'". Import the products first.');
            }

            app(ProcessReturnService::class)->process([
                'tenant_id' => $this->user->tenant_id,
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'type' => 'Sales Return',
                'qty' => max(1, (int) round((float) $line['units'])),
                'unit' => 'Pack',
                'amount' => $amount,
                'restocked' => false,
                'reason' => $reason,
                'return_date' => $row['issued_date'],
                'actor_id' => $this->user->id,
                'actor_name' => $this->user->name,
            ]);

            $created = true;
        }

        return $created ? 'created' : 'skipped';
    }

    /**
     * What a line comes to: units × price, less its discount (a fraction: 1 is free). Negative on a return.
     *
     * @param  array{units: mixed, price: mixed, discount: mixed}  $line
     */
    private function lineTotal(array $line): float
    {
        $number = fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0;

        return $number($line['units']) * $number($line['price']) * (1 - $number($line['discount']));
    }
}

<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Sales\Exceptions\InvoiceNotVoidableException;
use Modules\Sales\Models\Invoice;

/**
 * "Deleting" an invoice: invoices are never hard-deleted (spec §4.8), so
 * this marks one void, posts the reversing journal entry (Dr Sales Revenue
 * + VAT Payable / Cr Receivable) and takes the receivable back off the
 * customer's balance. An invoice that has had any money collected against it
 * is refused — those collections have to be dealt with first.
 */
class VoidInvoiceService
{
    public function __construct(
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function void(Invoice $invoice, array $actor = []): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'void') {
                throw new InvoiceNotVoidableException('This invoice is already void.');
            }

            if ((float) $locked->paid > 0 || $locked->collections()->exists()) {
                throw new InvoiceNotVoidableException('An invoice with collections against it cannot be voided.');
            }

            $total = (float) $locked->total;
            $tax = (float) $locked->tax_amount;

            $this->journalPosting->post(
                $locked->tenant_id,
                $locked->id,
                "Void of invoice {$locked->id}",
                array_values(array_filter([
                    ['account_code' => '4100', 'debit' => round($total - $tax, 2)],
                    ['account_code' => '2300', 'debit' => $tax],
                    ['account_code' => '1200', 'credit' => $total],
                ], fn (array $line) => ($line['debit'] ?? 0) > 0 || ($line['credit'] ?? 0) > 0)),
                $actor['actor_id'] ?? null,
            );

            // Only invoices that raised the customer's balance (credit orders,
            // imported invoices) give it back; collections floor at zero too.
            if ($locked->salesOrder === null || $locked->salesOrder->pay_type === 'credit') {
                $customer = $locked->customer;
                $customer->forceFill(['balance' => max(0, (float) $customer->balance - (float) $locked->balance)])->save();
            }

            $previousStatus = $locked->status;
            $locked->update(['status' => 'void', 'balance' => 0]);

            $this->auditLog->record([
                'module' => 'sales',
                'entity_type' => 'Invoice',
                'entity_id' => $locked->id,
                'operation' => 'UPDATE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'prev_values' => ['status' => $previousStatus],
                'new_values' => ['status' => 'void'],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $locked;
        });
    }
}

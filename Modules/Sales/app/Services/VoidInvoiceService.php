<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Sales\Exceptions\InvoiceNotVoidableException;
use Modules\Sales\Models\Invoice;

/**
 * "Deleting" an invoice (an imported one, or one cancelled together with its delivery by CancelDeliveryService): invoices are never hard-deleted (spec §4.8), so
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
     * @param  bool  $cancelling  True only when called by CancelDeliveryService, which has already put the stock back.
     */
    public function void(Invoice $invoice, array $actor = [], bool $cancelling = false): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor, $cancelling) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'void') {
                throw new InvoiceNotVoidableException('This invoice is already void.');
            }

            // An invoice whose goods have left the warehouse is undone by cancelling its delivery, which also puts the
            // stock back and reverses the cost of goods sold. Voiding it alone would leave the books and the stock apart.
            if (! $cancelling && $locked->salesOrder?->stock_deducted) {
                throw new InvoiceNotVoidableException('The goods for this invoice have left the warehouse. Cancel its delivery instead, which also returns the stock.');
            }

            if ((float) $locked->paid > 0 || $locked->collections()->exists()) {
                throw new InvoiceNotVoidableException('An invoice with collections against it cannot be voided.');
            }

            $total = (float) $locked->total;
            $tax = (float) $locked->tax_amount;
            $shipping = (float) $locked->shipping_amount;

            $this->journalPosting->post(
                $locked->tenant_id,
                $locked->id,
                "Void of invoice {$locked->id}",
                array_values(array_filter([
                    ['account_code' => '4100', 'debit' => round($total - $tax - $shipping, 2)],
                    ['account_code' => '4200', 'debit' => $shipping],
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

<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Sales\Exceptions\InvalidCollectionAmountException;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Invoice;

/**
 * Records a payment against an invoice (spec §5.5): rejects if the amount
 * is not positive, exceeds the invoice's remaining balance, or the invoice
 * is already fully paid; otherwise updates the invoice, decrements the
 * customer's AR balance (floored at 0), and auto-posts the journal entry
 * (§5.3) — all atomically.
 */
class CreateCollectionService
{
    public function __construct(
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{invoice_id: string, amount: float, method: string, reference?: ?string, payment_date?: ?string, notes?: ?string, collected_by?: ?string, actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $data
     */
    public function create(array $data): Collection
    {
        $invoice = Invoice::findOrFail($data['invoice_id']);
        $amount = (float) $data['amount'];

        if ($amount <= 0) {
            throw new InvalidCollectionAmountException('Collection amount must be greater than zero.');
        }

        if ($invoice->status === 'paid') {
            throw new InvalidCollectionAmountException('This invoice is already fully paid.');
        }

        if ($amount > (float) $invoice->balance) {
            throw new InvalidCollectionAmountException('Collection amount exceeds the invoice\'s remaining balance.');
        }

        return DB::transaction(function () use ($invoice, $amount, $data) {
            $newPaid = round((float) $invoice->paid + $amount, 2);
            $newBalance = round((float) $invoice->total - $newPaid, 2);

            $invoice->update([
                'paid' => $newPaid,
                'balance' => $newBalance,
                'status' => $newBalance <= 0 ? 'paid' : 'partial',
            ]);

            $invoice->customer()->update([
                'balance' => max(0, (float) $invoice->customer->balance - $amount),
            ]);

            $collection = Collection::create([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'collected_by' => $data['collected_by'] ?? null,
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            $cashAccountCode = $data['method'] === 'Cash' ? '1110' : '1120';

            $this->journalPosting->post(
                $invoice->tenant_id,
                $collection->id,
                "Collection for invoice {$invoice->id}",
                [
                    ['account_code' => $cashAccountCode, 'debit' => $amount],
                    ['account_code' => '1200', 'credit' => $amount],
                ],
                $data['actor_id'] ?? null,
            );

            $this->auditLog->record([
                'module' => 'sales',
                'entity_type' => 'Collection',
                'entity_id' => $collection->id,
                'operation' => 'INSERT',
                'user_id' => $data['actor_id'] ?? null,
                'user_name' => $data['actor_name'] ?? null,
                'tenant_id' => $invoice->tenant_id,
                'new_values' => ['invoice_id' => $invoice->id, 'amount' => (string) $amount],
                'ip_address' => $data['ip_address'] ?? null,
            ]);

            return $collection;
        });
    }
}

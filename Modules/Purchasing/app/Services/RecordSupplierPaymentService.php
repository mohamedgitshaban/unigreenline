<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Purchasing\Exceptions\InvalidSupplierPaymentException;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierPayment;

/**
 * Pays down what we owe a supplier: Dr Accounts Payable (2100) / Cr Cash
 * (1110) or Bank (1120), and the supplier's balance (negative = we owe)
 * moves toward zero. A payment can't exceed what is owed. The supplier row
 * is locked so two payments can't both spend the same debt.
 */
class RecordSupplierPaymentService
{
    public function __construct(
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{tenant_id: string, supplier_id: string, purchase_order_id?: ?string, amount: float|string, method: string, payment_date?: ?string, reference?: ?string, notes?: ?string, created_by?: ?string}  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function record(array $data, array $actor = []): SupplierPayment
    {
        return DB::transaction(function () use ($data, $actor) {
            $supplier = Supplier::query()->whereKey($data['supplier_id'])->lockForUpdate()->firstOrFail();
            $amount = round((float) $data['amount'], 2);
            $owed = round(max(0, -(float) $supplier->balance), 2);

            if ($amount > $owed) {
                throw new InvalidSupplierPaymentException(
                    $owed <= 0
                        ? "Nothing is owed to {$supplier->name}."
                        : "The payment of {$amount} is more than the {$owed} owed to {$supplier->name}."
                );
            }

            $payment = SupplierPayment::create([
                'tenant_id' => $data['tenant_id'],
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'amount' => $amount,
                'method' => $data['method'],
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $entry = $this->journalPosting->post(
                $payment->tenant_id,
                $payment->id,
                "Payment to supplier {$supplier->name}",
                [
                    ['account_code' => '2100', 'debit' => $amount],
                    ['account_code' => SupplierPayment::PAYMENT_ACCOUNT_CODES[$payment->method], 'credit' => $amount],
                ],
                $data['created_by'] ?? null,
                $payment->payment_date,
            );

            $payment->update(['journal_entry_id' => $entry->id]);
            $supplier->increment('balance', $amount);

            $this->auditLog->record([
                'module' => 'purchasing',
                'entity_type' => 'SupplierPayment',
                'entity_id' => $payment->id,
                'operation' => 'INSERT',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $payment->tenant_id,
                'new_values' => ['supplier_id' => $supplier->id, 'amount' => (string) $amount, 'method' => $payment->method],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $payment;
        });
    }
}

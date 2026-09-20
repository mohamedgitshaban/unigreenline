<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Services\GoodsReceiptService;
use Modules\Purchasing\Exceptions\IncompleteReceiptException;
use Modules\Purchasing\Exceptions\PurchaseOrderAlreadyReceivedException;
use Modules\Purchasing\Models\PurchaseOrder;

/**
 * Receives a purchase order (spec §5.2): for each line, adds stock via the
 * same product+warehouse+batch_no create-or-topup logic as manual GRN — a
 * real expiry date is required per line, never fabricated. Idempotent via
 * stock_added; a second call is rejected rather than silently re-processed,
 * so the caller knows their receipt data wasn't applied twice.
 */
class ReceivePurchaseOrderService
{
    public function __construct(
        private readonly GoodsReceiptService $goodsReceipt,
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array<int, array{line_id: string, batch_no: string, exp_date: string, mfg_date?: ?string, rcv_date?: ?string}>  $receipts
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function receive(PurchaseOrder $po, array $receipts, array $actor = []): PurchaseOrder
    {
        if ($po->stock_added) {
            throw new PurchaseOrderAlreadyReceivedException;
        }

        $lines = $po->lines()->get()->keyBy('id');
        $receiptsByLine = collect($receipts)->keyBy('line_id');

        if ($lines->keys()->diff($receiptsByLine->keys())->isNotEmpty() || $receiptsByLine->keys()->diff($lines->keys())->isNotEmpty()) {
            throw new IncompleteReceiptException;
        }

        return DB::transaction(function () use ($po, $lines, $receiptsByLine, $actor) {
            foreach ($lines as $line) {
                $receipt = $receiptsByLine[$line->id];

                $this->goodsReceipt->receive([
                    'tenant_id' => $po->tenant_id,
                    'product_id' => $line->product_id,
                    'warehouse_id' => $po->warehouse_id,
                    'batch_no' => $receipt['batch_no'],
                    'qty_cartons' => $line->qty_cartons,
                    'cost_per_carton' => (float) $line->cost_per_carton,
                    'exp_date' => $receipt['exp_date'],
                    'mfg_date' => $receipt['mfg_date'] ?? null,
                    'rcv_date' => $receipt['rcv_date'] ?? null,
                ]);
            }

            $po->update([
                'stock_added' => true,
                'status' => 'received',
                'received_date' => now()->toDateString(),
            ]);

            $po->supplier()->decrement('balance', $po->total);

            $this->journalPosting->post(
                $po->tenant_id,
                $po->id,
                "Goods receipt for purchase order {$po->id}",
                [
                    ['account_code' => '1300', 'debit' => (float) $po->total],
                    ['account_code' => '2100', 'credit' => (float) $po->total],
                ],
                $actor['actor_id'] ?? null,
            );

            $this->auditLog->record([
                'module' => 'purchasing',
                'entity_type' => 'PurchaseOrder',
                'entity_id' => $po->id,
                'operation' => 'APPROVE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $po->tenant_id,
                'warehouse_id' => $po->warehouse_id,
                'new_values' => ['status' => 'received'],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $po->fresh(['lines']);
        });
    }
}

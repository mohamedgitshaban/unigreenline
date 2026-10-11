<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\GoodsReceiptService;
use Modules\Inventory\Services\UnitConverter;
use Modules\Purchasing\Exceptions\IncompleteReceiptException;
use Modules\Purchasing\Exceptions\PurchaseOrderAlreadyReceivedException;
use Modules\Purchasing\Exceptions\PurchaseOrderNotPricedException;
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
        private readonly UnitConverter $units,
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array<int, array{line_id: string, batch_no: string, exp_date: string, mfg_date?: ?string, rcv_date?: ?string}>  $receipts
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @param  array{invoice_no?: string, invoice_date?: ?string, amount?: ?string, file_path?: ?string}|null  $invoiceData  The supplier's invoice.
     * @param  array<int, array{product_id: string, unit_id?: ?string, qty: int, batch_no: string, exp_date: string, mfg_date?: ?string}>  $freeGifts  Bonus stock delivered with the order, added at zero cost.
     */
    public function receive(PurchaseOrder $po, array $receipts, array $actor = [], ?array $invoiceData = null, array $freeGifts = []): PurchaseOrder
    {
        if ($po->stock_added) {
            throw new PurchaseOrderAlreadyReceivedException;
        }

        if ($po->status === 'pending_pricing') {
            throw new PurchaseOrderNotPricedException;
        }

        $lines = $po->lines()->get()->keyBy('id');
        $receiptsByLine = collect($receipts)->keyBy('line_id');

        if ($lines->keys()->diff($receiptsByLine->keys())->isNotEmpty() || $receiptsByLine->keys()->diff($lines->keys())->isNotEmpty()) {
            throw new IncompleteReceiptException;
        }

        return DB::transaction(function () use ($po, $lines, $receiptsByLine, $actor, $invoiceData, $freeGifts) {
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

            foreach ($freeGifts as $index => $gift) {
                $product = Product::query()->where('tenant_id', $po->tenant_id)->findOrFail($gift['product_id']);

                $this->goodsReceipt->receive([
                    'tenant_id' => $po->tenant_id,
                    'product_id' => $product->id,
                    'warehouse_id' => $po->warehouse_id,
                    'batch_no' => $gift['batch_no'],
                    'qty_cartons' => empty($gift['unit_id']) ? (int) $gift['qty'] : $this->units->toCartons($product, (int) $gift['qty'], $gift['unit_id'], "free_gifts.{$index}.qty"),
                    'cost_per_carton' => 0,
                    'exp_date' => $gift['exp_date'],
                    'mfg_date' => $gift['mfg_date'] ?? null,
                    'is_free_gift' => true,
                    'source_po_id' => $po->id,
                ]);
            }

            $po->update([
                'stock_added' => true,
                'status' => 'received',
                'received_date' => now()->toDateString(),
                'supplier_invoice_no' => $invoiceData['invoice_no'] ?? null,
                'supplier_invoice_date' => $invoiceData['invoice_date'] ?? null,
                'supplier_invoice_amount' => $invoiceData['amount'] ?? null,
                'supplier_invoice_path' => $invoiceData['file_path'] ?? null,
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
                'new_values' => ['status' => 'received'] + ($freeGifts === [] ? [] : ['free_gifts' => count($freeGifts)]),
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $po->fresh(['lines']);
        });
    }
}

<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Support\StockMovementContext;

/**
 * Stock that arrives without a purchase order: a manual goods receipt, and the opening stock entered when a product
 * or a warehouse is first set up. Stock goes into its batch (written to the stock ledger) and its value is booked
 * against Opening Balance Equity (Dr Inventory / Cr Opening Balance Equity), since there is no supplier to owe.
 */
class ManualGoodsReceiptService
{
    public function __construct(
        private readonly GoodsReceiptService $goodsReceipt,
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{tenant_id: string, product_id: string, warehouse_id: string, batch_no: string, qty_cartons: int, cost_per_carton: float|int|string, exp_date: string, mfg_date?: ?string, rcv_date?: ?string}  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function receive(array $data, array $actor = [], string $description = 'Manual goods receipt'): InventoryBatch
    {
        return DB::transaction(function () use ($data, $actor, $description) {
            $batch = StockMovementContext::run(
                ['type' => 'goods_receipt', 'user_id' => $actor['actor_id'] ?? null],
                fn () => $this->goodsReceipt->receive($data),
            );

            $value = round($data['qty_cartons'] * (float) $data['cost_per_carton'], 2);

            if ($value > 0) {
                $this->journalPosting->post(
                    $data['tenant_id'],
                    $batch->id,
                    "{$description}, batch {$batch->batch_no}",
                    [
                        ['account_code' => '1300', 'debit' => $value],
                        ['account_code' => '3300', 'credit' => $value],
                    ],
                    $actor['actor_id'] ?? null,
                );
            }

            $this->auditLog->record([
                'module' => 'inventory',
                'entity_type' => 'InventoryBatch',
                'entity_id' => $batch->id,
                'operation' => 'INSERT',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $data['tenant_id'],
                'warehouse_id' => $batch->warehouse_id,
                'new_values' => [
                    'batch_no' => $batch->batch_no,
                    'qty_cartons_received' => $data['qty_cartons'],
                ],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $batch;
        });
    }
}

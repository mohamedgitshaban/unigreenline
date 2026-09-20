<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Warehouse;

/**
 * Manual goods receipt — POST /inventory/grn (spec §6), for stock that
 * doesn't come from a purchase order. §5.2's PO-receiving flow will call
 * the same storage logic once the Purchasing module exists.
 *
 * The spec's §5.2 wording ("find the most-recently-received existing batch
 * for that product+warehouse") is read here as product+warehouse+batch_no:
 * matching on product+warehouse alone would merge physically distinct
 * batches with different expiry dates into one row, which breaks both FEFO
 * ordering and the table's own unique(tenant_id, batch_no, warehouse_id)
 * constraint. A repeat receipt of the same batch_no tops up that row; a new
 * batch_no always gets its own row.
 */
class GoodsReceiptService
{
    /**
     * @param  array{
     *     tenant_id: string,
     *     product_id: string,
     *     warehouse_id: string,
     *     batch_no: string,
     *     qty_cartons: int,
     *     cost_per_carton: float,
     *     exp_date: string,
     *     mfg_date?: string|null,
     *     rcv_date?: string|null,
     * }  $data
     */
    public function receive(array $data): InventoryBatch
    {
        return DB::transaction(function () use ($data) {
            $batch = InventoryBatch::query()
                ->where('tenant_id', $data['tenant_id'])
                ->where('product_id', $data['product_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->where('batch_no', $data['batch_no'])
                ->lockForUpdate()
                ->first();

            if ($batch) {
                $batch->increment('qty_cartons', $data['qty_cartons']);
            } else {
                $batch = InventoryBatch::create([
                    'tenant_id' => $data['tenant_id'],
                    'product_id' => $data['product_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'batch_no' => $data['batch_no'],
                    'mfg_date' => $data['mfg_date'] ?? null,
                    'exp_date' => $data['exp_date'],
                    'rcv_date' => $data['rcv_date'] ?? now(),
                    'qty_cartons' => $data['qty_cartons'],
                    'qty_packs' => 0,
                    'cost_per_carton' => $data['cost_per_carton'],
                ]);
            }

            Warehouse::find($data['warehouse_id'])?->recalculateStockValue();

            return $batch->fresh();
        });
    }
}

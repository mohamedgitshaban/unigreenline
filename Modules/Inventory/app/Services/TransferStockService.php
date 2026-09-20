<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;

/**
 * Inter-warehouse stock transfer (spec §5.8): moves quantity out of the
 * source batch (deleting it if it hits zero) into the destination
 * warehouse's batch of the same batch_no — creating it, with the source
 * batch's expiry carried over, if it doesn't already exist there. One
 * atomic operation; both warehouses' stock_value are recalculated after.
 */
class TransferStockService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{
     *     tenant_id: string, product_id: string, from_warehouse_id: string, to_warehouse_id: string,
     *     batch_no: string, qty_cartons: int, transfer_date?: ?string, notes?: ?string, created_by?: ?string,
     *     actor_id?: ?string, actor_name?: ?string, ip_address?: ?string,
     * }  $data
     */
    public function transfer(array $data): Transfer
    {
        return DB::transaction(function () use ($data) {
            $sourceBatch = InventoryBatch::query()
                ->where('tenant_id', $data['tenant_id'])
                ->where('product_id', $data['product_id'])
                ->where('warehouse_id', $data['from_warehouse_id'])
                ->where('batch_no', $data['batch_no'])
                ->lockForUpdate()
                ->first();

            $available = $sourceBatch->qty_cartons ?? 0;

            if (! $sourceBatch || $available < $data['qty_cartons']) {
                throw new InsufficientStockException([[
                    'product_id' => $data['product_id'],
                    'warehouse_id' => $data['from_warehouse_id'],
                    'needed' => $data['qty_cartons'],
                    'available' => $available,
                ]]);
            }

            $expDate = $sourceBatch->exp_date;
            $mfgDate = $sourceBatch->mfg_date;
            $costPerCarton = $sourceBatch->cost_per_carton;

            $sourceBatch->decrement('qty_cartons', $data['qty_cartons']);

            if ($sourceBatch->fresh()->qty_cartons === 0) {
                $sourceBatch->delete();
            }

            $destinationBatch = InventoryBatch::query()
                ->where('tenant_id', $data['tenant_id'])
                ->where('product_id', $data['product_id'])
                ->where('warehouse_id', $data['to_warehouse_id'])
                ->where('batch_no', $data['batch_no'])
                ->lockForUpdate()
                ->first();

            if ($destinationBatch) {
                $destinationBatch->increment('qty_cartons', $data['qty_cartons']);
            } else {
                InventoryBatch::create([
                    'tenant_id' => $data['tenant_id'],
                    'product_id' => $data['product_id'],
                    'warehouse_id' => $data['to_warehouse_id'],
                    'batch_no' => $data['batch_no'],
                    'mfg_date' => $mfgDate,
                    'exp_date' => $expDate,
                    'rcv_date' => now(),
                    'qty_cartons' => $data['qty_cartons'],
                    'qty_packs' => 0,
                    'cost_per_carton' => $costPerCarton,
                ]);
            }

            $transfer = Transfer::create([
                'tenant_id' => $data['tenant_id'],
                'product_id' => $data['product_id'],
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id' => $data['to_warehouse_id'],
                'batch_no' => $data['batch_no'],
                'qty_cartons' => $data['qty_cartons'],
                'transfer_date' => $data['transfer_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'status' => 'completed',
                'created_by' => $data['created_by'] ?? null,
            ]);

            Warehouse::find($data['from_warehouse_id'])?->recalculateStockValue();
            Warehouse::find($data['to_warehouse_id'])?->recalculateStockValue();

            $this->auditLog->record([
                'module' => 'inventory',
                'entity_type' => 'Transfer',
                'entity_id' => $transfer->id,
                'operation' => 'INSERT',
                'user_id' => $data['actor_id'] ?? null,
                'user_name' => $data['actor_name'] ?? null,
                'tenant_id' => $data['tenant_id'],
                'warehouse_id' => $data['to_warehouse_id'],
                'new_values' => [
                    'product_id' => $data['product_id'],
                    'from_warehouse_id' => $data['from_warehouse_id'],
                    'to_warehouse_id' => $data['to_warehouse_id'],
                    'qty_cartons' => $data['qty_cartons'],
                ],
                'ip_address' => $data['ip_address'] ?? null,
            ]);

            return $transfer;
        });
    }
}

<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Exceptions\ReturnBatchNotFoundException;
use Modules\Sales\Models\ReturnRecord;
use Modules\Sales\Support\CartonCalculator;

/**
 * Records a return and moves stock accordingly (spec §5.9):
 *  - "Purchase Return" (going back to the supplier) always deducts from the
 *    matching batch — stock physically leaves, regardless of `restocked`.
 *  - The four customer-facing types (Sales Return/Damaged/Expired
 *    Return/Wrong Item) only move stock when `restocked` is true, adding
 *    back to the matching batch — false means it's written off, not
 *    returned to sellable stock.
 * Neither direction ever fabricates a batch: the returns table has no
 * expiry field, so the matching batch must already exist.
 * No financial side effect (no invoice/customer-balance change) — the
 * spec describes only the stock movement for returns.
 */
class ProcessReturnService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{
     *     tenant_id: string, invoice_id?: ?string, customer_id?: ?string, supplier_id?: ?string,
     *     product_id?: ?string, warehouse_id?: ?string, batch_no?: ?string, type: string,
     *     qty: int, unit: string, amount: float, restocked?: bool, reason?: ?string, return_date?: ?string,
     *     actor_id?: ?string, actor_name?: ?string, ip_address?: ?string,
     * }  $data
     */
    public function process(array $data): ReturnRecord
    {
        $restocked = $data['restocked'] ?? false;

        return DB::transaction(function () use ($data, $restocked) {
            if ($data['type'] === 'Purchase Return') {
                $this->deductFromBatch($data);
            } elseif ($restocked) {
                $this->addToBatch($data);
            }

            $return = ReturnRecord::create([
                'tenant_id' => $data['tenant_id'],
                'invoice_id' => $data['invoice_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'product_id' => $data['product_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'batch_no' => $data['batch_no'] ?? null,
                'type' => $data['type'],
                'qty' => $data['qty'],
                'unit' => $data['unit'],
                'amount' => $data['amount'],
                'restocked' => $restocked,
                'reason' => $data['reason'] ?? null,
                'status' => 'completed',
                'return_date' => $data['return_date'] ?? now()->toDateString(),
            ]);

            $this->auditLog->record([
                'module' => 'sales',
                'entity_type' => 'ReturnRecord',
                'entity_id' => $return->id,
                'operation' => 'INSERT',
                'user_id' => $data['actor_id'] ?? null,
                'user_name' => $data['actor_name'] ?? null,
                'tenant_id' => $data['tenant_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'new_values' => ['type' => $data['type'], 'qty' => $data['qty'], 'restocked' => $restocked],
                'ip_address' => $data['ip_address'] ?? null,
            ]);

            return $return;
        });
    }

    private function cartonsFor(array $data): int
    {
        $product = Product::findOrFail($data['product_id']);

        return CartonCalculator::cartonsNeeded($data['qty'], 0, $data['unit'], $product->carton_qty);
    }

    private function deductFromBatch(array $data): void
    {
        $cartons = $this->cartonsFor($data);

        $batch = InventoryBatch::query()
            ->where('tenant_id', $data['tenant_id'])
            ->where('product_id', $data['product_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('batch_no', $data['batch_no'])
            ->lockForUpdate()
            ->first();

        if (! $batch) {
            throw new ReturnBatchNotFoundException($data['product_id'], $data['warehouse_id'], $data['batch_no']);
        }

        if ($batch->qty_cartons < $cartons) {
            throw new InsufficientStockException([[
                'product_id' => $data['product_id'],
                'warehouse_id' => $data['warehouse_id'],
                'needed' => $cartons,
                'available' => $batch->qty_cartons,
            ]]);
        }

        $batch->decrement('qty_cartons', $cartons);

        if ($batch->fresh()->qty_cartons === 0) {
            $batch->delete();
        }

        Warehouse::find($data['warehouse_id'])?->recalculateStockValue();
    }

    private function addToBatch(array $data): void
    {
        $cartons = $this->cartonsFor($data);

        $batch = InventoryBatch::query()
            ->where('tenant_id', $data['tenant_id'])
            ->where('product_id', $data['product_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('batch_no', $data['batch_no'])
            ->lockForUpdate()
            ->first();

        if (! $batch) {
            throw new ReturnBatchNotFoundException($data['product_id'], $data['warehouse_id'], $data['batch_no']);
        }

        $batch->increment('qty_cartons', $cartons);

        Warehouse::find($data['warehouse_id'])?->recalculateStockValue();
    }
}

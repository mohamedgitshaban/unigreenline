<?php

namespace Modules\Inventory\Observers;

use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Support\StockMovementContext;

/**
 * Writes the stock ledger: every change to a batch's quantity — a new batch, or cartons added or taken —
 * becomes a {@see StockMovement}, typed by the surrounding {@see StockMovementContext}.
 */
class InventoryBatchObserver
{
    public function created(InventoryBatch $batch): void
    {
        if ($batch->qty_cartons > 0) {
            $this->record($batch, $batch->qty_cartons);
        }
    }

    public function updated(InventoryBatch $batch): void
    {
        if (! $batch->wasChanged('qty_cartons')) {
            return;
        }

        $delta = $batch->qty_cartons - (int) $batch->getOriginal('qty_cartons');

        if ($delta !== 0) {
            $this->record($batch, $delta);
        }
    }

    private function record(InventoryBatch $batch, int $delta): void
    {
        $context = StockMovementContext::current();

        StockMovement::create([
            'tenant_id' => $batch->tenant_id,
            'warehouse_id' => $batch->warehouse_id,
            'product_id' => $batch->product_id,
            'batch_id' => $batch->id,
            'batch_no' => $batch->batch_no,
            'qty_cartons' => $delta,
            'unit_cost' => $batch->cost_per_carton ?? 0,
            'is_free_gift' => (bool) $batch->is_free_gift,
            'type' => $context['type'] ?? 'adjustment',
            'reference_type' => $context['reference_type'] ?? null,
            'reference_id' => $context['reference_id'] ?? null,
            'counterpart_warehouse_id' => $context['counterpart_warehouse_id'] ?? null,
            'user_id' => $context['user_id'] ?? auth()->id(),
            'notes' => $context['notes'] ?? null,
            'occurred_at' => now(),
        ]);
    }
}

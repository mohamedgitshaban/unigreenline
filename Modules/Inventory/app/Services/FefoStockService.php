<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Warehouse;

/**
 * First-Expired, First-Out stock deduction (spec §5.1). This service is
 * deliberately decoupled from any order model — callers (e.g. the future
 * Sales module, when a sales order moves to invoiced/delivered) pass plain
 * line arrays and are responsible for their own idempotency flag (like
 * sales_orders.stock_deducted) before calling deduct().
 */
class FefoStockService
{
    /**
     * Returns shortfalls grouped by product+warehouse; empty means every line is satisfiable.
     *
     * @param  array<int, array{product_id: string, warehouse_id: string, cartons_needed: int}>  $lines
     * @return array<int, array{product_id: string, warehouse_id: string, needed: int, available: int}>
     */
    public function checkAvailability(array $lines): array
    {
        $shortfalls = [];

        foreach ($this->groupByProductWarehouse($lines) as $group) {
            $available = $this->availableCartons($group['product_id'], $group['warehouse_id']);

            if ($available < $group['needed']) {
                $shortfalls[] = [
                    'product_id' => $group['product_id'],
                    'warehouse_id' => $group['warehouse_id'],
                    'needed' => $group['needed'],
                    'available' => $available,
                ];
            }
        }

        return $shortfalls;
    }

    /**
     * Deducts every line atomically, earliest-expiring batch first. Throws
     * InsufficientStockException with no row written if any product+warehouse
     * combination cannot be fully satisfied.
     *
     * @param  array<int, array{product_id: string, warehouse_id: string, cartons_needed: int}>  $lines
     * @return array<int, array{product_id: string, warehouse_id: string, batch_id: string, batch_no: string, cartons_taken: int}>
     */
    public function deduct(array $lines): array
    {
        return DB::transaction(function () use ($lines) {
            $shortfalls = $this->checkAvailability($lines);

            if ($shortfalls !== []) {
                throw new InsufficientStockException($shortfalls);
            }

            $deductions = [];
            $warehouseIdsTouched = [];

            foreach ($lines as $line) {
                $remaining = $line['cartons_needed'];

                $batches = InventoryBatch::query()
                    ->where('product_id', $line['product_id'])
                    ->where('warehouse_id', $line['warehouse_id'])
                    ->where('qty_cartons', '>', 0)
                    ->orderBy('exp_date')
                    ->orderBy('rcv_date')
                    ->lockForUpdate()
                    ->get();

                foreach ($batches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, $batch->qty_cartons);
                    $batch->decrement('qty_cartons', $take);
                    $remaining -= $take;

                    $deductions[] = [
                        'product_id' => $line['product_id'],
                        'warehouse_id' => $line['warehouse_id'],
                        'batch_id' => $batch->id,
                        'batch_no' => $batch->batch_no,
                        'cartons_taken' => $take,
                    ];
                }

                // Guards against a race between checkAvailability() and the
                // row locks above; checkAvailability already makes this the
                // rare path, not the primary enforcement point.
                if ($remaining > 0) {
                    throw new InsufficientStockException([[
                        'product_id' => $line['product_id'],
                        'warehouse_id' => $line['warehouse_id'],
                        'needed' => $line['cartons_needed'],
                        'available' => $line['cartons_needed'] - $remaining,
                    ]]);
                }

                $warehouseIdsTouched[$line['warehouse_id']] = true;
            }

            foreach (array_keys($warehouseIdsTouched) as $warehouseId) {
                Warehouse::find($warehouseId)?->recalculateStockValue();
            }

            return $deductions;
        });
    }

    private function availableCartons(string $productId, string $warehouseId): int
    {
        return (int) InventoryBatch::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('qty_cartons', '>', 0)
            ->sum('qty_cartons');
    }

    /**
     * Sums cartons_needed across lines targeting the same product+warehouse
     * pair. Two lines for the same pair must not each be checked against the
     * full available quantity independently — that would double-count stock
     * one of them would already consume.
     *
     * @param  array<int, array{product_id: string, warehouse_id: string, cartons_needed: int}>  $lines
     * @return Collection<string, array{product_id: string, warehouse_id: string, needed: int}>
     */
    private function groupByProductWarehouse(array $lines): Collection
    {
        return collect($lines)
            ->groupBy(fn (array $line) => $line['product_id'].'|'.$line['warehouse_id'])
            ->map(fn (Collection $group) => [
                'product_id' => $group->first()['product_id'],
                'warehouse_id' => $group->first()['warehouse_id'],
                'needed' => $group->sum('cartons_needed'),
            ]);
    }
}

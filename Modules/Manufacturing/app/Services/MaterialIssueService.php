<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockReservationService;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductionOrderComponent;

/**
 * Takes material out of the order's warehouse. The order's own reservation is
 * used first (earliest-expiring batch first), then free stock, also earliest
 * expiry first. Expired batches are never issued; batch rows are locked in the
 * same order every other stock flow uses, so concurrent issues cannot deadlock
 * or double-spend a carton.
 */
class MaterialIssueService
{
    public function __construct(private readonly StockReservationService $reservations) {}

    /**
     * @return array<int, array{batch_id: string, batch_no: string, cartons: int, cost_per_carton: float}>
     *
     * @throws InsufficientStockException when the warehouse cannot cover it; nothing is taken then.
     */
    public function issue(ProductionOrder $order, ProductionOrderComponent $component, int $cartons): array
    {
        if ($cartons <= 0) {
            return [];
        }

        return DB::transaction(function () use ($order, $component, $cartons) {
            $taken = [];

            foreach ($this->reservations->consume(MaterialReservationService::SOURCE, $order->id, $component->id, $cartons) as $row) {
                $taken[] = ['batch_id' => $row['batch_id'], 'batch_no' => $row['batch_no'], 'cartons' => $row['cartons_taken']];
            }

            $remaining = $cartons - array_sum(array_column($taken, 'cartons'));

            if ($remaining > 0) {
                $batches = InventoryBatch::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('product_id', $component->product_id)
                    ->where('warehouse_id', $order->warehouse_id)
                    ->where('is_free_gift', false)
                    ->whereDate('exp_date', '>=', today())
                    ->whereColumn('qty_cartons', '>', 'reserved_cartons')
                    ->orderBy('exp_date')->orderBy('rcv_date')->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($batches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, $batch->freeCartons());
                    $batch->decrement('qty_cartons', $take);
                    $remaining -= $take;
                    $taken[] = ['batch_id' => $batch->id, 'batch_no' => $batch->batch_no, 'cartons' => $take];
                }
            }

            if ($remaining > 0) {
                throw new InsufficientStockException([[
                    'product_id' => $component->product_id,
                    'warehouse_id' => $order->warehouse_id,
                    'needed' => $cartons,
                    'available' => $cartons - $remaining,
                ]]);
            }

            $costs = InventoryBatch::query()->whereIn('id', array_column($taken, 'batch_id'))->get(['id', 'cost_per_carton', 'exp_date'])->keyBy('id');

            foreach ($taken as &$row) {
                $batch = $costs[$row['batch_id']];

                if ($batch->exp_date->lt(today())) {
                    throw new ManufacturingRuleException("Batch {$row['batch_no']} expired on {$batch->exp_date->toDateString()} and cannot be used in production. Release the reservation and write the batch off.");
                }

                $row['cost_per_carton'] = (float) $batch->cost_per_carton;
            }
            unset($row);

            Warehouse::query()->find($order->warehouse_id)?->recalculateStockValue();

            return $taken;
        });
    }
}

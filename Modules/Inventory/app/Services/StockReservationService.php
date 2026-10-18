<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\StockReservation;

/**
 * Sets stock aside for a document without moving it, and later turns the
 * reservation into a real withdrawal.
 *
 * Every method locks the affected batch rows first, in FEFO order, and keeps
 * `inventory_batches.reserved_cartons` in step. Anything else that takes stock
 * (sales deduction, transfers, write-offs, returns) reads that column under the
 * same row lock, so a carton can never be both reserved here and spent there.
 * Expired batches are never reserved.
 */
class StockReservationService
{
    /**
     * Reserves up to `$cartons` earliest-expiring free cartons. Returns how many
     * were actually reserved (the caller decides whether a partial result is acceptable).
     */
    public function reserve(
        string $tenantId,
        string $productId,
        string $warehouseId,
        int $cartons,
        string $sourceType,
        string $sourceId,
        ?string $sourceLineId = null,
        ?string $userId = null,
    ): int {
        if ($cartons <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($tenantId, $productId, $warehouseId, $cartons, $sourceType, $sourceId, $sourceLineId, $userId) {
            $batches = InventoryBatch::query()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->where('is_free_gift', false)
                ->whereDate('exp_date', '>=', today())
                ->whereColumn('qty_cartons', '>', 'reserved_cartons')
                ->orderBy('exp_date')->orderBy('rcv_date')->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remaining = $cartons;

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, $batch->freeCartons());

                $existing = StockReservation::query()->active()
                    ->where('source_type', $sourceType)->where('source_id', $sourceId)
                    ->where('source_line_id', $sourceLineId)->where('batch_id', $batch->id)
                    ->lockForUpdate()->first();

                if ($existing) {
                    $existing->increment('qty_cartons', $take);
                } else {
                    StockReservation::create([
                        'tenant_id' => $tenantId, 'product_id' => $productId, 'warehouse_id' => $warehouseId,
                        'batch_id' => $batch->id, 'qty_cartons' => $take, 'status' => 'active',
                        'source_type' => $sourceType, 'source_id' => $sourceId, 'source_line_id' => $sourceLineId,
                        'created_by' => $userId,
                    ]);
                }

                $batch->increment('reserved_cartons', $take);
                $remaining -= $take;
            }

            return $cartons - $remaining;
        }, 3);
    }

    /**
     * Gives reserved cartons back. `$cartons` null releases everything the source holds
     * (for that line, if given); otherwise releases that many, latest-expiring first.
     */
    public function release(string $sourceType, string $sourceId, ?string $sourceLineId = null, ?int $cartons = null): int
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $sourceLineId, $cartons) {
            $reservations = $this->lockedReservations($sourceType, $sourceId, $sourceLineId, latestFirst: true);
            $remaining = $cartons ?? PHP_INT_MAX;
            $released = 0;

            foreach ($reservations as $reservation) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, $reservation->qty_cartons);
                InventoryBatch::query()->whereKey($reservation->batch_id)->decrement('reserved_cartons', $take);

                if ($take === $reservation->qty_cartons) {
                    $reservation->update(['status' => 'released']);
                } else {
                    $reservation->decrement('qty_cartons', $take);
                }

                $remaining -= $take;
                $released += $take;
            }

            return $released;
        });
    }

    /**
     * Withdraws reserved stock for real: the source's own reservations, earliest-expiring
     * batch first. Returns the batches taken from; may return fewer cartons than asked
     * if the source holds fewer reservations.
     *
     * @return array<int, array{batch_id: string, batch_no: string, cartons_taken: int}>
     */
    public function consume(string $sourceType, string $sourceId, ?string $sourceLineId, int $cartons): array
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $sourceLineId, $cartons) {
            $reservations = $this->lockedReservations($sourceType, $sourceId, $sourceLineId, latestFirst: false);
            $remaining = $cartons;
            $taken = [];

            foreach ($reservations as $reservation) {
                if ($remaining <= 0) {
                    break;
                }

                $batch = $reservation->batch;
                // A batch can hold fewer cartons than were reserved only if its stock was changed behind our back;
                // take what is really there and let the caller find the rest elsewhere or fail.
                $take = min($remaining, $reservation->qty_cartons, $batch->qty_cartons);

                if ($take <= 0) {
                    continue;
                }

                $batch->decrement('qty_cartons', $take);
                $batch->decrement('reserved_cartons', $take);

                if ($take === $reservation->qty_cartons) {
                    $reservation->update(['status' => 'consumed']);
                } else {
                    $reservation->decrement('qty_cartons', $take);
                }

                $taken[] = ['batch_id' => $batch->id, 'batch_no' => $batch->batch_no, 'cartons_taken' => $take];
                $remaining -= $take;
            }

            return $taken;
        });
    }

    public function reservedFor(string $sourceType, string $sourceId, ?string $sourceLineId = null): int
    {
        return (int) StockReservation::query()->active()
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->when($sourceLineId, fn ($q) => $q->where('source_line_id', $sourceLineId))
            ->sum('qty_cartons');
    }

    /**
     * Free (unreserved, unexpired, non-gift) cartons of a product in a warehouse.
     */
    public function freeCartons(string $productId, string $warehouseId): int
    {
        return (int) InventoryBatch::query()
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->where('is_free_gift', false)->whereDate('exp_date', '>=', today())
            ->whereColumn('qty_cartons', '>', 'reserved_cartons')
            ->sum(DB::raw('qty_cartons - reserved_cartons'));
    }

    /**
     * Active reservations with their batches locked (FEFO order, so concurrent callers lock in the same order).
     *
     * @return Collection<int, StockReservation>
     */
    private function lockedReservations(string $sourceType, string $sourceId, ?string $sourceLineId, bool $latestFirst)
    {
        $ids = StockReservation::query()->active()
            ->select('stock_reservations.id')
            ->join('inventory_batches', 'inventory_batches.id', '=', 'stock_reservations.batch_id')
            ->where('stock_reservations.source_type', $sourceType)
            ->where('stock_reservations.source_id', $sourceId)
            ->when($sourceLineId, fn ($q) => $q->where('stock_reservations.source_line_id', $sourceLineId))
            ->orderBy('inventory_batches.exp_date', $latestFirst ? 'desc' : 'asc')
            ->orderBy('inventory_batches.rcv_date', $latestFirst ? 'desc' : 'asc')
            ->orderBy('inventory_batches.id', $latestFirst ? 'desc' : 'asc')
            ->pluck('stock_reservations.id');

        $reservations = collect();

        foreach ($ids as $id) {
            $reservation = StockReservation::query()->whereKey($id)->lockForUpdate()->first();

            if ($reservation === null || $reservation->status !== 'active') {
                continue;
            }

            $reservation->setRelation('batch', InventoryBatch::query()->whereKey($reservation->batch_id)->lockForUpdate()->firstOrFail());
            $reservations->push($reservation);
        }

        return $reservations;
    }
}

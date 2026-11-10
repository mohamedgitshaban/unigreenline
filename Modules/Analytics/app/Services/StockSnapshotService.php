<?php

namespace Modules\Analytics\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock by product, warehouse and batch at a point in time: cartons, expiry, what they cost, and whether the
 * product's stock in that warehouse is at or below its minimum. Today's figure is read off the batches; a past
 * date is worked out by adding up the stock ledger (every in and out, written since it was introduced, with an
 * opening balance per batch) up to the end of that day.
 */
class StockSnapshotService
{
    /**
     * @param  array<int, string>|null  $visibleWarehouseIds  Restricts the rows to these warehouses, or null for all.
     * @return Collection<int, object>
     */
    public function rows(string $tenantId, ?CarbonInterface $asOf, ?string $warehouseId, ?string $productId, ?array $visibleWarehouseIds): Collection
    {
        $historical = $asOf !== null && ! $asOf->isToday() && $asOf->isPast();

        $query = DB::table('inventory_batches as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.tenant_id', $tenantId)
            ->when($warehouseId, fn ($q, string $id) => $q->where('b.warehouse_id', $id))
            ->when($productId, fn ($q, string $id) => $q->where('b.product_id', $id))
            ->when($visibleWarehouseIds !== null, fn ($q) => $q->whereIn('b.warehouse_id', $visibleWarehouseIds))
            ->select([
                'p.id as product_id', 'p.name as product_name', 'p.sku', 'p.brand', 'p.min_stock_cartons', 'p.reorder_level',
                'w.id as warehouse_id', 'w.name as warehouse_name',
                'b.id as batch_id', 'b.batch_no', 'b.exp_date', 'b.cost_per_carton', 'b.is_free_gift',
            ]);

        if ($historical) {
            $end = $asOf->copy()->endOfDay();
            $query->selectRaw('coalesce((select sum(m.qty_cartons) from stock_movements m where m.batch_id = b.id and m.occurred_at <= ?), 0) as qty', [$end->toDateTimeString()]);
        } else {
            $query->addSelect('b.qty_cartons as qty');
        }

        $rows = $query->orderBy('p.name')->orderBy('w.name')->orderBy('b.exp_date')->orderBy('b.batch_no')->get()
            ->filter(fn ($row) => (int) $row->qty > 0)
            ->values();

        // At or below the minimum is judged on regular stock only, per product and warehouse.
        $regular = $rows->where('is_free_gift', 0)->groupBy(fn ($row) => $row->product_id.'|'.$row->warehouse_id)->map(fn (Collection $group) => (int) $group->sum('qty'));

        return $rows->map(function ($row) use ($regular) {
            $row->qty = (int) $row->qty;
            $row->at_or_below_minimum = $row->min_stock_cartons > 0 && ($regular[$row->product_id.'|'.$row->warehouse_id] ?? 0) <= $row->min_stock_cartons;

            return $row;
        });
    }
}

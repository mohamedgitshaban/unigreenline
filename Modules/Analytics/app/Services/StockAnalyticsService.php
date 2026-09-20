<?php

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;

class StockAnalyticsService
{
    /**
     * Per-product stock rollup across warehouses (spec §6). Paginated by
     * product; each row lists the per-warehouse breakdown.
     */
    public function generate(string $tenantId, int $perPage = 15, int $page = 1): array
    {
        $productIdsQuery = DB::table('inventory_batches')
            ->join('products', 'products.id', '=', 'inventory_batches.product_id')
            ->where('inventory_batches.tenant_id', $tenantId)
            ->where('inventory_batches.qty_cartons', '>', 0)
            ->select('inventory_batches.product_id')
            ->distinct();

        $total = (clone $productIdsQuery)->count();

        $productIds = $productIdsQuery
            ->orderBy('inventory_batches.product_id')
            ->forPage($page, $perPage)
            ->pluck('product_id');

        $rows = DB::table('inventory_batches')
            ->join('products', 'products.id', '=', 'inventory_batches.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_batches.warehouse_id')
            ->whereIn('inventory_batches.product_id', $productIds)
            ->where('inventory_batches.qty_cartons', '>', 0)
            ->selectRaw('
                products.id as product_id, products.name as product_name, products.sku,
                warehouses.id as warehouse_id, warehouses.name as warehouse_name,
                SUM(inventory_batches.qty_cartons) as qty_cartons,
                SUM(inventory_batches.qty_cartons * products.pack_cost_price * products.carton_qty) as stock_value
            ')
            ->groupBy('products.id', 'products.name', 'products.sku', 'warehouses.id', 'warehouses.name')
            ->get();

        $data = $rows->groupBy('product_id')->map(function ($productRows) {
            $first = $productRows->first();

            return [
                'product_id' => $first->product_id,
                'product_name' => $first->product_name,
                'sku' => $first->sku,
                'total_qty_cartons' => (int) $productRows->sum('qty_cartons'),
                'total_stock_value' => number_format((float) $productRows->sum('stock_value'), 2, '.', ''),
                'warehouses' => $productRows->map(fn ($row) => [
                    'warehouse_id' => $row->warehouse_id,
                    'warehouse_name' => $row->warehouse_name,
                    'qty_cartons' => (int) $row->qty_cartons,
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }
}

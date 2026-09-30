<?php

namespace Modules\Analytics\Http\Controllers;

use App\Exports\GenericExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel;
use Modules\Analytics\Http\Resources\ExpiryBatchResource;
use Modules\Analytics\Services\DashboardService;
use Modules\Analytics\Services\ExpiryAnalyticsService;
use Modules\Analytics\Services\StockAnalyticsService;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Inventory\Models\InventoryBatch;

class AnalyticsController extends Controller
{
    use Exportable;

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ExpiryAnalyticsService $expiryAnalytics,
        private readonly StockAnalyticsService $stockAnalytics,
    ) {}

    public function dashboard(Request $request)
    {
        $this->authorize('analytics.view');

        return response()->json(['data' => $this->dashboard->generate($request->user()->tenant_id)]);
    }

    public function expiry(Request $request)
    {
        $this->authorize('analytics.view');

        $query = $this->expiryAnalytics->query($request->user()->tenant_id);

        if ($export = $this->exportIfRequested(
            $request, 'analytics.export', $query,
            ['Batch ID', 'Product', 'Warehouse', 'Batch No', 'Exp Date', 'Qty Cartons', 'Days Left'],
            fn (InventoryBatch $b) => [
                $b->id, $b->product?->name, $b->warehouse?->name, $b->batch_no, $b->exp_date->toDateString(), $b->qty_cartons,
                (int) ceil((strtotime($b->exp_date->toDateString()) - strtotime(now()->toDateString())) / 86400),
            ],
            'expiry-tracking',
        )) {
            return $export;
        }

        $batches = $this->expiryAnalytics->generate($request->user()->tenant_id, $request->integer('per_page', 15));

        return ExpiryBatchResource::collection($batches);
    }

    public function stock(Request $request)
    {
        $this->authorize('analytics.view');

        if ($export = $this->exportStockIfRequested($request)) {
            return $export;
        }

        return response()->json(
            $this->stockAnalytics->generate($request->user()->tenant_id, $request->integer('per_page', 15), $request->integer('page', 1))
        );
    }

    /**
     * Stock rollup's response is nested (one product with a per-warehouse
     * breakdown array), which doesn't fit Exportable's one-query-row-per-
     * spreadsheet-row shape — this flattens to one row per product+
     * warehouse instead, which is what a spreadsheet actually wants anyway.
     */
    private function exportStockIfRequested(Request $request): mixed
    {
        $format = $request->query('export');

        if (! in_array($format, ['csv', 'xlsx'], true)) {
            return null;
        }

        abort_unless($request->user()->can('analytics.export'), 403);

        $rows = collect($this->stockAnalytics->rows($request->user()->tenant_id))
            ->map(fn ($row) => [$row->product_id, $row->product_name, $row->sku, $row->warehouse_id, $row->warehouse_name, (int) $row->qty_cartons, (float) $row->stock_value]);

        return \Maatwebsite\Excel\Facades\Excel::download(
            new GenericExport(
                ['Product ID', 'Product Name', 'SKU', 'Warehouse ID', 'Warehouse Name', 'Qty Cartons', 'Stock Value'],
                $rows,
            ),
            'stock-'.now()->format('Y-m-d').'.'.$format,
            $format === 'csv' ? Excel::CSV : Excel::XLSX,
        );
    }
}

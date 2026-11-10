<?php

namespace Modules\Analytics\Http\Controllers;

use App\Exports\GenericExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Analytics\Services\StockSnapshotService;
use Modules\Inventory\Support\WarehouseAccess;

/**
 * GET /analytics/stock-snapshot: stock by product, warehouse and batch on a given day (default today), with who
 * produced the report and when. What the stock cost is shown to the Administrator only, like every cost figure.
 */
class StockSnapshotController extends Controller
{
    public function __construct(private readonly StockSnapshotService $snapshot, private readonly WarehouseAccess $access) {}

    public function show(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $filters = $request->validate([
            'as_of' => ['sometimes', 'date'],
            'warehouse_id' => ['sometimes', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'product_id' => ['sometimes', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'below_minimum' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $asOf = isset($filters['as_of']) ? now()->parse($filters['as_of']) : now();
        $showValue = $request->user()->hasRole('Administrator');

        $rows = $this->snapshot->rows($tenantId, $asOf, $filters['warehouse_id'] ?? null, $filters['product_id'] ?? null, $this->access->idsFor($request->user()));

        if ($request->boolean('below_minimum')) {
            $rows = $rows->where('at_or_below_minimum', true)->values();
        }

        $totals = ['cartons' => (int) $rows->sum('qty'), 'value' => $showValue ? number_format($rows->sum(fn ($row) => $row->qty * (float) $row->cost_per_carton), 2, '.', '') : null, 'rows' => $rows->count()];
        $report = ['as_of' => $asOf->toDateString(), 'generated_at' => now()->toIso8601String(), 'generated_by' => ['id' => $request->user()->id, 'name' => $request->user()->name]];

        if (in_array($request->query('export'), ['csv', 'xlsx'], true)) {
            return $this->download($request, $rows, $showValue, $report);
        }

        $perPage = $request->integer('per_page', 50);
        $page = $request->integer('page', 1);

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->map(fn ($row) => [
                'product' => ['id' => $row->product_id, 'name' => $row->product_name, 'sku' => $row->sku, 'brand' => $row->brand],
                'warehouse' => ['id' => $row->warehouse_id, 'name' => $row->warehouse_name],
                'batch_no' => $row->batch_no,
                'exp_date' => $row->exp_date,
                'qty_cartons' => $row->qty,
                'is_free_gift' => (bool) $row->is_free_gift,
                'value' => $showValue ? number_format($row->qty * (float) $row->cost_per_carton, 2, '.', '') : null,
                'min_stock_cartons' => (int) $row->min_stock_cartons,
                'reorder_level' => (int) $row->reorder_level,
                'at_or_below_minimum' => $row->at_or_below_minimum,
            ])->values(),
            'report' => $report + ['totals' => $totals],
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => (int) max(1, ceil($rows->count() / $perPage))],
        ]);
    }

    /**
     * @param  array{as_of: string, generated_at: string, generated_by: array{id: string, name: string}}  $report
     */
    private function download(Request $request, $rows, bool $showValue, array $report)
    {
        $permission = $request->route()?->getAction('export_permission');
        abort_unless($permission !== null && $request->user()->can($permission), 403);

        $format = $request->query('export');
        $headings = ['Product', 'SKU', 'Warehouse', 'Batch No', 'Expiry', 'Cartons', ...($showValue ? ['Value'] : []), 'At or below minimum', 'As of', 'Produced by'];
        $body = $rows->map(fn ($row) => [
            $row->product_name, $row->sku, $row->warehouse_name, $row->batch_no, $row->exp_date, $row->qty,
            ...($showValue ? [round($row->qty * (float) $row->cost_per_carton, 2)] : []),
            $row->at_or_below_minimum ? 'Yes' : 'No', $report['as_of'], $report['generated_by']['name'],
        ])->values();

        return Excel::download(new GenericExport($headings, $body), 'stock-snapshot-'.$report['as_of'].'.'.$format, $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX);
    }
}

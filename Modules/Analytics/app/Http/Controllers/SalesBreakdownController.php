<?php

namespace Modules\Analytics\Http\Controllers;

use App\Exports\GenericExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Analytics\Services\SalesBreakdownService;
use Modules\Core\Support\DataScope;

/**
 * GET /reports/sales-breakdown: sales grouped by customer, item, sales rep, category, sub-class, brand or supplier.
 * A sales rep (or anyone limited to their team) only sees their team's orders.
 */
class SalesBreakdownController extends Controller
{
    public function __construct(private readonly SalesBreakdownService $breakdown, private readonly DataScope $scope) {}

    public function show(Request $request)
    {
        $filters = $request->validate([
            'group_by' => ['required', Rule::in(SalesBreakdownService::GROUPS)],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'],
            'warehouse_id' => ['sometimes', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $rows = $this->breakdown->generate(
            $request->user()->tenant_id,
            $filters['group_by'],
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
            $filters['warehouse_id'] ?? null,
            $this->scope->visibleUserIds($request->user()),
        );

        if (in_array($request->query('export'), ['csv', 'xlsx'], true)) {
            $permission = $request->route()?->getAction('export_permission');
            abort_unless($permission !== null && $request->user()->can($permission), 403);

            $format = $request->query('export');

            return Excel::download(
                new GenericExport([ucfirst($filters['group_by']), 'Orders', 'Lines', 'Amount'], $rows->map(fn ($row) => [$row->name, $row->orders, $row->lines, $row->amount])->values()),
                "sales-by-{$filters['group_by']}-".now()->format('Y-m-d').".{$format}",
                $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
            );
        }

        return response()->json(['data' => [
            'group_by' => $filters['group_by'],
            'from' => $filters['start_date'] ?? null,
            'to' => $filters['end_date'] ?? null,
            'rows' => $rows->values(),
            'total_amount' => number_format($rows->sum(fn ($row) => (float) $row->amount), 2, '.', ''),
        ]]);
    }
}

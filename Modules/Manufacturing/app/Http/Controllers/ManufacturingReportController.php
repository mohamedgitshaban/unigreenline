<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Exports\GenericExport;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Manufacturing\Models\WorkCenter;
use Modules\Manufacturing\Services\WorkCenterScheduler;

/**
 * Manufacturing reports. Each is a database aggregation (grouped, filtered and paged in SQL — nothing is
 * loaded wholesale into PHP), takes a date range and the usual product / warehouse / status filters, and
 * can be exported (`?export=csv|xlsx`) with the report's export permission. Reports that expose cost
 * need a cost permission; the others show quantities only. How each number is worked out is in
 * docs/manufacturing/api-reference.md.
 */
class ManufacturingReportController extends Controller
{
    public function __construct(private readonly WorkCenterScheduler $scheduler) {}

    /**
     * Planned against produced quantity by product, month or warehouse.
     */
    public function production(Request $request)
    {
        $group = $request->validate(['group_by' => ['sometimes', 'in:product,month,warehouse']])['group_by'] ?? 'product';
        [$label, $key] = match ($group) {
            'month' => ["DATE_FORMAT(o.production_date, '%Y-%m')", "DATE_FORMAT(o.production_date, '%Y-%m')"],
            'warehouse' => ['w.name', 'o.warehouse_id'],
            default => ['p.name', 'b.product_id'],
        };

        $query = $this->orders($request)
            ->join('warehouses as w', 'w.id', '=', 'o.warehouse_id')
            ->selectRaw("{$key} as `key`, {$label} as label, COUNT(*) as orders, SUM(o.output_qty_cartons) as planned_qty, SUM(o.produced_qty_cartons) as produced_qty, SUM(o.rejected_qty_cartons) as rejected_qty")
            ->groupByRaw('`key`, label')
            ->orderBy('label');

        return $this->respond($request, $query, ['Group', 'Orders', 'Planned', 'Produced', 'Rejected', 'Completion %', 'Yield %'], fn ($r) => [
            $r->label, (int) $r->orders, (int) $r->planned_qty, (int) $r->produced_qty, (int) $r->rejected_qty, $this->pct($r->produced_qty, $r->planned_qty), $this->pct($r->produced_qty, $r->produced_qty + $r->rejected_qty),
        ], fn ($r) => [
            'key' => $r->key, 'label' => $r->label, 'orders' => (int) $r->orders, 'planned_qty' => (int) $r->planned_qty, 'produced_qty' => (int) $r->produced_qty,
            'rejected_qty' => (int) $r->rejected_qty, 'completion_pct' => $this->pct($r->produced_qty, $r->planned_qty), 'yield_pct' => $this->pct($r->produced_qty, $r->produced_qty + $r->rejected_qty),
        ], 'manufacturing-production');
    }

    /**
     * What was issued to production, by component: quantity used and wasted (and the value, for those who may see cost).
     */
    public function materialConsumption(Request $request)
    {
        $costs = $request->user()->can('production-orders.costs');
        [$from, $to] = $this->range($request);

        $query = DB::table('production_consumptions as c')
            ->join('production_orders as o', 'o.id', '=', 'c.production_order_id')
            ->join('products as p', 'p.id', '=', 'c.product_id')
            ->where('o.tenant_id', $request->user()->tenant_id)
            ->whereDate('c.created_at', '>=', $from)->whereDate('c.created_at', '<=', $to)
            ->when($request->filled('product_id'), fn ($q) => $q->where('c.product_id', $request->input('product_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('o.warehouse_id', $request->input('warehouse_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('o.status', $request->input('status')))
            ->selectRaw("c.product_id, p.name, p.sku, SUM(CASE WHEN c.kind = 'consume' THEN c.qty_cartons ELSE 0 END) as used_qty, SUM(CASE WHEN c.kind = 'scrap' THEN c.qty_cartons ELSE 0 END) as scrap_qty, SUM(CASE WHEN c.kind = 'consume' THEN c.line_value ELSE 0 END) as used_value, SUM(CASE WHEN c.kind = 'scrap' THEN c.line_value ELSE 0 END) as scrap_value, COUNT(DISTINCT c.production_order_id) as orders")
            ->groupBy('c.product_id', 'p.name', 'p.sku')->orderBy('p.name');

        return $this->respond($request, $query, ['SKU', 'Component', 'Orders', 'Used (cartons)', 'Scrapped (cartons)', ...($costs ? ['Used value', 'Scrap value'] : [])], fn ($r) => [
            $r->sku, $r->name, (int) $r->orders, (int) $r->used_qty, (int) $r->scrap_qty, ...($costs ? [round($r->used_value, 2), round($r->scrap_value, 2)] : []),
        ], fn ($r) => [
            'product' => ['id' => $r->product_id, 'name' => $r->name, 'sku' => $r->sku], 'orders' => (int) $r->orders,
            'used_qty' => (int) $r->used_qty, 'scrap_qty' => (int) $r->scrap_qty,
        ] + ($costs ? ['used_value' => round((float) $r->used_value, 2), 'scrap_value' => round((float) $r->scrap_value, 2)] : []), 'manufacturing-material-consumption');
    }

    /**
     * Scrap and rejection: wasted material and rejected output by reason, and the rejection rate of the period.
     */
    public function scrap(Request $request)
    {
        $costs = $request->user()->can('production-orders.costs');
        [$from, $to] = $this->range($request);

        $query = DB::table('production_scraps as s')
            ->join('production_orders as o', 'o.id', '=', 's.production_order_id')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('s.tenant_id', $request->user()->tenant_id)->where('s.status', 'approved')
            ->whereDate('s.created_at', '>=', $from)->whereDate('s.created_at', '<=', $to)
            ->when($request->filled('product_id'), fn ($q) => $q->where('s.product_id', $request->input('product_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('o.warehouse_id', $request->input('warehouse_id')))
            ->when($request->filled('kind'), fn ($q) => $q->where('s.kind', $request->input('kind')))
            ->selectRaw('s.kind, s.reason, s.product_id, p.name, COUNT(*) as records, SUM(s.qty_cartons) as qty, SUM(s.scrap_cost) as cost')
            ->groupBy('s.kind', 's.reason', 's.product_id', 'p.name')->orderByDesc(DB::raw('SUM(s.qty_cartons)'));

        $rate = $this->orders($request)->selectRaw('COALESCE(SUM(o.produced_qty_cartons), 0) as produced, COALESCE(SUM(o.rejected_qty_cartons), 0) as rejected')->first();

        return $this->respond($request, $query, ['Kind', 'Reason', 'Product', 'Records', 'Cartons', ...($costs ? ['Cost'] : [])], fn ($r) => [
            $r->kind, $r->reason, $r->name, (int) $r->records, (int) $r->qty, ...($costs ? [round($r->cost, 2)] : []),
        ], fn ($r) => [
            'kind' => $r->kind, 'reason' => $r->reason, 'product' => ['id' => $r->product_id, 'name' => $r->name], 'records' => (int) $r->records, 'qty' => (int) $r->qty,
        ] + ($costs ? ['cost' => round((float) $r->cost, 2)] : []), 'manufacturing-scrap', ['rejection_rate_pct' => $this->pct($rate->rejected, $rate->produced + $rate->rejected), 'produced_qty' => (int) $rate->produced, 'rejected_qty' => (int) $rate->rejected]);
    }

    /**
     * How busy each work center was: working time available, time booked, time actually worked.
     */
    public function workCenterUtilization(Request $request)
    {
        [$from, $to] = $this->range($request, 30);
        $tenantId = $request->user()->tenant_id;
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $centers = WorkCenter::query()->where('tenant_id', $tenantId)
            ->when($request->filled('work_center_id'), fn ($q) => $q->whereKey($request->input('work_center_id')))
            ->with(['calendar', 'downtimes'])->orderBy('code')->get();

        $booked = DB::table('work_orders')->where('tenant_id', $tenantId)->whereIn('work_center_id', $centers->pluck('id'))->where('status', '!=', 'cancelled')
            ->whereBetween('scheduled_start', [$start, $end])
            ->selectRaw('work_center_id, SUM(planned_minutes) as planned, COUNT(*) as operations')->groupBy('work_center_id')->get()->keyBy('work_center_id');

        $worked = DB::table('work_orders')->where('tenant_id', $tenantId)->whereIn('work_center_id', $centers->pluck('id'))->where('status', 'completed')
            ->whereBetween('actual_end', [$start, $end])
            ->selectRaw('work_center_id, SUM(actual_minutes) as actual, SUM(planned_minutes) as planned_of_done, COUNT(*) as done')->groupBy('work_center_id')->get()->keyBy('work_center_id');

        $rows = $centers->map(function (WorkCenter $center) use ($start, $end, $booked, $worked) {
            $available = $this->scheduler->availableMinutes($center, $start, $end) * $center->capacity;
            $scheduled = (int) ($booked[$center->id]->planned ?? 0);
            $actual = (int) ($worked[$center->id]->actual ?? 0);
            $plannedOfDone = (int) ($worked[$center->id]->planned_of_done ?? 0);

            return [
                'work_center' => ['id' => $center->id, 'code' => $center->code, 'name' => $center->name, 'capacity' => $center->capacity],
                'available_minutes' => $available,
                'scheduled_minutes' => $scheduled,
                'actual_minutes' => $actual,
                'operations_scheduled' => (int) ($booked[$center->id]->operations ?? 0),
                'operations_completed' => (int) ($worked[$center->id]->done ?? 0),
                'planned_minutes_of_completed' => $plannedOfDone,
                'duration_variance_minutes' => $actual - $plannedOfDone,
                'schedule_load_pct' => $this->pct($scheduled, $available),
                'utilization_pct' => $this->pct($actual, $available),
            ];
        })->values();

        if ($export = $this->exportRows($request, ['Code', 'Work center', 'Available (min)', 'Scheduled (min)', 'Worked (min)', 'Duration variance (min)', 'Load %', 'Utilization %'], $rows->map(fn ($r) => [
            $r['work_center']['code'], $r['work_center']['name'], $r['available_minutes'], $r['scheduled_minutes'], $r['actual_minutes'], $r['duration_variance_minutes'], $r['schedule_load_pct'], $r['utilization_pct'],
        ]), 'manufacturing-work-center-utilization')) {
            return $export;
        }

        return response()->json(['from' => $from, 'to' => $to, 'data' => $rows]);
    }

    /**
     * Completed orders against their plan: how late, how long they took.
     */
    public function leadTimes(Request $request)
    {
        [$from, $to] = $this->range($request, 90);

        $base = DB::table('production_orders as o')
            ->join('boms as b', 'b.id', '=', 'o.bom_id')->join('products as p', 'p.id', '=', 'b.product_id')
            ->where('o.tenant_id', $request->user()->tenant_id)->where('o.status', 'completed')
            ->whereDate('o.completed_at', '>=', $from)->whereDate('o.completed_at', '<=', $to)
            ->when($request->filled('product_id'), fn ($q) => $q->where('b.product_id', $request->input('product_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('o.warehouse_id', $request->input('warehouse_id')));

        $summary = (clone $base)->selectRaw('COUNT(*) as orders, AVG(TIMESTAMPDIFF(MINUTE, o.actual_start, o.actual_finish)) as avg_minutes, SUM(CASE WHEN o.planned_finish IS NOT NULL AND o.actual_finish <= o.planned_finish THEN 1 ELSE 0 END) as on_time, SUM(CASE WHEN o.planned_finish IS NOT NULL THEN 1 ELSE 0 END) as with_plan')->first();

        $query = (clone $base)->selectRaw('o.id, o.number, p.name as product, o.planned_start, o.planned_finish, o.actual_start, o.actual_finish, TIMESTAMPDIFF(MINUTE, o.actual_start, o.actual_finish) as lead_minutes, TIMESTAMPDIFF(MINUTE, o.planned_finish, o.actual_finish) as delay_minutes')
            ->orderByDesc('o.completed_at');

        return $this->respond($request, $query, ['Order', 'Product', 'Planned finish', 'Actual finish', 'Lead time (min)', 'Delay (min)'], fn ($r) => [
            $r->number, $r->product, $r->planned_finish, $r->actual_finish, $r->lead_minutes, $r->delay_minutes,
        ], fn ($r) => [
            'id' => $r->id, 'number' => $r->number, 'product' => $r->product, 'planned_start' => $r->planned_start, 'planned_finish' => $r->planned_finish,
            'actual_start' => $r->actual_start, 'actual_finish' => $r->actual_finish, 'lead_minutes' => $r->lead_minutes === null ? null : (int) $r->lead_minutes,
            'delay_minutes' => $r->delay_minutes === null ? null : (int) $r->delay_minutes, 'on_time' => $r->delay_minutes === null ? null : (int) $r->delay_minutes <= 0,
        ], 'manufacturing-lead-times', [
            'orders' => (int) $summary->orders, 'avg_lead_minutes' => $summary->avg_minutes === null ? null : round((float) $summary->avg_minutes, 1),
            'on_time_pct' => $this->pct($summary->on_time, $summary->with_plan),
        ]);
    }

    /**
     * Where a batch came from and where it went. Give `batch_no`: if it is a finished batch, the order that made it
     * and the material batches that went into it; if it is a material batch, the orders that used it and what they made.
     */
    public function traceability(Request $request)
    {
        $data = $request->validate(['batch_no' => ['required', 'string', 'max:255']]);
        $tenantId = $request->user()->tenant_id;

        $madeBy = DB::table('production_outputs as out')
            ->join('production_orders as o', 'o.id', '=', 'out.production_order_id')->join('products as p', 'p.id', '=', 'out.product_id')
            ->where('o.tenant_id', $tenantId)->where('out.batch_no', $data['batch_no'])
            ->get(['o.id as order_id', 'o.number', 'out.kind', 'p.name as product', 'out.qty_cartons', 'out.created_at']);

        $usedIn = DB::table('production_consumptions as c')
            ->join('production_orders as o', 'o.id', '=', 'c.production_order_id')->join('products as p', 'p.id', '=', 'c.product_id')
            ->where('o.tenant_id', $tenantId)->where('c.batch_no', $data['batch_no'])
            ->get(['o.id as order_id', 'o.number', 'c.kind', 'p.name as product', 'c.qty_cartons', 'c.created_at']);

        $inputs = $madeBy->isEmpty() ? collect() : DB::table('production_consumptions as c')
            ->join('products as p', 'p.id', '=', 'c.product_id')->whereIn('c.production_order_id', $madeBy->pluck('order_id'))
            ->get(['c.production_order_id as order_id', 'c.kind', 'p.name as product', 'c.batch_no', 'c.qty_cartons']);

        $outputs = $usedIn->isEmpty() ? collect() : DB::table('production_outputs as out')
            ->join('products as p', 'p.id', '=', 'out.product_id')->whereIn('out.production_order_id', $usedIn->pluck('order_id'))
            ->get(['out.production_order_id as order_id', 'out.kind', 'p.name as product', 'out.batch_no', 'out.qty_cartons']);

        return response()->json(['batch_no' => $data['batch_no'], 'data' => [
            'made_by' => $madeBy->map(fn ($r) => (array) $r + ['inputs' => $inputs->where('order_id', $r->order_id)->map(fn ($i) => ['kind' => $i->kind, 'product' => $i->product, 'batch_no' => $i->batch_no, 'qty_cartons' => $i->qty_cartons])->values()])->values(),
            'used_in' => $usedIn->map(fn ($r) => (array) $r + ['outputs' => $outputs->where('order_id', $r->order_id)->map(fn ($o) => ['kind' => $o->kind, 'product' => $o->product, 'batch_no' => $o->batch_no, 'qty_cartons' => $o->qty_cartons])->values()])->values(),
        ]]);
    }

    /**
     * Planned against actual cost of completed orders (cost permission). Planned figures are scaled to what was made.
     */
    public function costVariance(Request $request)
    {
        $query = $this->orders($request, completedOnly: true)
            ->selectRaw('o.id, o.number, p.name as product, o.output_qty_cartons, o.produced_qty_cartons, o.rejected_qty_cartons, o.planned_material_cost, o.planned_operation_cost, o.planned_overhead_cost, o.material_cost, o.actual_operation_cost, o.actual_overhead_cost, o.scrap_cost, o.capitalized_cost, o.variance_cost, o.completed_at')
            ->orderByDesc('o.completed_at');

        return $this->respond($request, $query, ['Order', 'Product', 'Planned', 'Actual', 'Variance', 'Variance %', 'Scrap', 'Unit cost'], fn ($r) => [
            $r->number, $r->product, ...array_values($this->variance($r)),
        ], fn ($r) => ['id' => $r->id, 'number' => $r->number, 'product' => $r->product, 'completed_at' => $r->completed_at] + $this->variance($r), 'manufacturing-cost-variance');
    }

    /**
     * Work in progress (cost permission): for orders booking through account 1350, what is in progress per order, and the ledger balance to reconcile with.
     */
    public function wipValuation(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $query = DB::table('production_orders as o')
            ->join('boms as b', 'b.id', '=', 'o.bom_id')->join('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin(DB::raw("(SELECT production_order_id, SUM(line_value) AS material FROM production_consumptions WHERE kind = 'consume' OR absorbed = 1 GROUP BY production_order_id) m"), 'm.production_order_id', '=', 'o.id')
            ->leftJoin(DB::raw("(SELECT production_order_id, SUM(labor_cost + machine_cost) AS operation FROM work_orders WHERE status = 'completed' GROUP BY production_order_id) w"), 'w.production_order_id', '=', 'o.id')
            ->where('o.tenant_id', $tenantId)->where('o.wip_accounting', true)->whereIn('o.status', ['confirmed', 'planned', 'in_progress', 'paused'])
            ->selectRaw('o.id, o.number, o.status, p.name as product, COALESCE(m.material, 0) as material, COALESCE(w.operation, 0) as operation, o.actual_overhead_cost as overhead, o.capitalized_cost as capitalized')
            ->orderBy('o.number');

        $rows = (clone $query)->get()->map(fn ($r) => [
            'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'product' => $r->product,
            'material' => round((float) $r->material, 2), 'operation' => round((float) $r->operation, 2), 'overhead' => round((float) $r->overhead, 2),
            'capitalized' => round((float) $r->capitalized, 2),
            'wip' => round((float) $r->material + (float) $r->operation + (float) $r->overhead - (float) $r->capitalized, 2),
        ]);

        $ledger = (float) DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', '1350')->value('balance');

        if ($export = $this->exportRows($request, ['Order', 'Status', 'Product', 'Material', 'Operations', 'Overhead', 'Carried to stock', 'In progress'], $rows->map(fn ($r) => [$r['number'], $r['status'], $r['product'], $r['material'], $r['operation'], $r['overhead'], $r['capitalized'], $r['wip']]), 'manufacturing-wip')) {
            return $export;
        }

        return response()->json(['data' => $rows, 'total_wip' => round($rows->sum('wip'), 2), 'ledger_balance_1350' => round($ledger, 2)]);
    }

    /**
     * Production orders of the company in the date range, with their product, filtered as asked.
     * Cancelled orders are left out unless asked for.
     */
    private function orders(Request $request, bool $completedOnly = false): Builder
    {
        [$from, $to] = $this->range($request, $completedOnly ? 90 : 3650);

        return DB::table('production_orders as o')
            ->join('boms as b', 'b.id', '=', 'o.bom_id')->join('products as p', 'p.id', '=', 'b.product_id')
            ->where('o.tenant_id', $request->user()->tenant_id)
            ->when($completedOnly, fn ($q) => $q->where('o.status', 'completed')->whereDate('o.completed_at', '>=', $from)->whereDate('o.completed_at', '<=', $to),
                fn ($q) => $q->whereDate('o.production_date', '>=', $from)->whereDate('o.production_date', '<=', $to))
            ->when($request->filled('status'), fn ($q) => $q->where('o.status', $request->input('status')), fn ($q) => $q->where('o.status', '!=', 'cancelled'))
            ->when($request->filled('product_id'), fn ($q) => $q->where('b.product_id', $request->input('product_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('o.warehouse_id', $request->input('warehouse_id')));
    }

    /**
     * @return array{planned: float, actual: float, variance: float, variance_pct: ?float, scrap: float, unit_cost: ?float}
     */
    private function variance(object $r): array
    {
        $made = $r->produced_qty_cartons + $r->rejected_qty_cartons;
        $scale = $r->output_qty_cartons > 0 ? $made / $r->output_qty_cartons : 1;
        $planned = ((float) $r->planned_material_cost + (float) $r->planned_operation_cost + (float) $r->planned_overhead_cost) * $scale;
        $actual = (float) $r->material_cost + (float) $r->actual_operation_cost + (float) $r->actual_overhead_cost;

        return [
            'planned' => round($planned, 2), 'actual' => round($actual, 2), 'variance' => round($actual - $planned, 2),
            'variance_pct' => $planned > 0 ? round(($actual - $planned) / $planned * 100, 1) : null,
            'scrap' => round((float) $r->scrap_cost, 2),
            'unit_cost' => $r->produced_qty_cartons > 0 ? round((float) $r->capitalized_cost / $r->produced_qty_cartons, 2) : null,
        ];
    }

    /**
     * Paged JSON or a file, from one query. `$extra` is merged into the JSON beside the rows.
     *
     * @param  array<int, string>  $headings
     * @param  Closure(object): array<int, mixed>  $exportRow
     * @param  Closure(object): array<string, mixed>  $jsonRow
     * @param  array<string, mixed>  $extra
     */
    private function respond(Request $request, Builder $query, array $headings, Closure $exportRow, Closure $jsonRow, string $filename, array $extra = [])
    {
        if ($export = $this->exportRows($request, $headings, fn () => $query->get()->map($exportRow)->values(), $filename)) {
            return $export;
        }

        $page = $query->paginate($request->integer('per_page', 25))->withQueryString();

        return response()->json($extra + [
            'data' => collect($page->items())->map($jsonRow)->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * A file download when asked for and allowed, otherwise null. The route's export permission is required: fail closed.
     *
     * @param  array<int, string>  $headings
     * @param  Collection<int, array<int, mixed>>|Closure(): Collection<int, array<int, mixed>>  $rows
     */
    private function exportRows(Request $request, array $headings, Collection|Closure $rows, string $filename)
    {
        $format = $request->query('export');

        if (! in_array($format, ['csv', 'xlsx'], true)) {
            return null;
        }

        $permission = $request->route()?->getAction('export_permission');
        abort_unless($permission !== null && $request->user()->can($permission), 403);

        $rows = $rows instanceof Closure ? $rows() : $rows;

        return Excel::download(new GenericExport($headings, $rows->values()), $filename.'-'.now()->format('Y-m-d').'.'.$format, $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(Request $request, int $defaultDays = 30): array
    {
        $data = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from']]);

        $to = Carbon::parse($data['to'] ?? now())->toDateString();
        $from = Carbon::parse($data['from'] ?? Carbon::parse($to)->subDays($defaultDays))->toDateString();

        return [$from, $to];
    }

    private function pct(float|int|string|null $part, float|int|string|null $whole): ?float
    {
        return (float) $whole > 0 ? round((float) $part / (float) $whole * 100, 1) : null;
    }
}

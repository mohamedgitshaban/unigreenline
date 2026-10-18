<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Http\Resources\ProductionOrderResource;
use Modules\Manufacturing\Models\MrpRun;
use Modules\Manufacturing\Models\MrpRunLine;
use Modules\Manufacturing\Models\MrpSuggestion;
use Modules\Manufacturing\Services\MrpConversionService;
use Modules\Manufacturing\Services\MrpRunService;

/**
 * Planning runs, their requirements report, the shortages found, and the suggestions proposed.
 */
class MrpController extends Controller
{
    use Exportable, Filterable, Sortable;

    public function __construct(
        private readonly MrpRunService $runs,
        private readonly MrpConversionService $conversion,
    ) {}

    public function runs(Request $request)
    {
        $query = MrpRun::query()->where('tenant_id', $request->user()->tenant_id)->with('requestedBy:id,name');
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')));

        $this->applyFilters($request, $query, ['number']);
        $this->applySorting($request, $query);

        $page = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => $this->runData($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function showRun(Request $request, MrpRun $run)
    {
        abort_unless($run->tenant_id === $request->user()->tenant_id, 404);

        return response()->json(['data' => $this->runData($run->load('requestedBy:id,name'))]);
    }

    /**
     * Records and runs a planning run. By default it runs now and the response is the finished run; with
     * `async: true` it is queued and the response (202) is the waiting run — poll it for its outcome.
     */
    public function plan(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'horizon_days' => ['nullable', 'integer', 'between:1,730'],
            'async' => ['sometimes', 'boolean'],
        ]);

        $run = $this->runs->create($request->user()->tenant_id, $data, $request->user()->id);

        if ($request->boolean('async')) {
            $this->runs->dispatch($run);

            return response()->json(['data' => $this->runData($run->refresh())], 202);
        }

        $run = $this->runs->execute($run->id);

        return response()->json(['data' => $this->runData($run)], 201);
    }

    /**
     * The requirements report: per product, demand, stock, incoming supply, what is short and what is proposed.
     */
    public function requirements(Request $request)
    {
        $run = $this->resolveRun($request);

        $query = MrpRunLine::query()->where('mrp_run_id', $run->id)->with(['product:id,name,sku', 'warehouse:id,name']);
        $query->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')));
        $query->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->input('product_id')));
        $query->when($request->filled('level'), fn ($q) => $q->where('level', $request->integer('level')));
        $query->when($request->boolean('short_only'), fn ($q) => $q->where('planned_qty', '>', 0));

        $this->applyFilters($request, $query, ['product.name', 'product.sku']);
        $this->applySorting($request, $query);

        $page = $query->orderBy('level')->paginate($request->integer('per_page', 25))->withQueryString();

        return response()->json([
            'run' => ['id' => $run->id, 'number' => $run->number, 'finished_at' => $run->finished_at],
            'data' => $page->getCollection()->map(fn (MrpRunLine $l) => $this->lineData($l))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * What is short, how much, and what is proposed to cover it.
     */
    public function shortages(Request $request)
    {
        $run = $this->resolveRun($request);

        $lines = MrpRunLine::query()->where('mrp_run_id', $run->id)->where('planned_qty', '>', 0)
            ->with(['product:id,name,sku,supplier_id', 'warehouse:id,name'])->orderBy('level')->orderBy('due_date')->get();

        $suggestions = MrpSuggestion::query()->where('tenant_id', $run->tenant_id)->where('mrp_run_id', $run->id)->get()->groupBy(fn ($s) => $s->product_id.'|'.$s->warehouse_id);

        return response()->json([
            'run' => ['id' => $run->id, 'number' => $run->number, 'finished_at' => $run->finished_at],
            'data' => $lines->map(fn (MrpRunLine $l) => $this->lineData($l) + [
                'shortage_cartons' => $l->planned_qty,
                'suggestions' => ($suggestions[$l->product_id.'|'.$l->warehouse_id] ?? collect())->map(fn ($s) => $this->suggestionData($s))->values(),
                'needs_supplier' => $l->action === 'purchase' && $l->product?->supplier_id === null,
            ])->values(),
        ]);
    }

    public function suggestions(Request $request)
    {
        $query = MrpSuggestion::query()->where('tenant_id', $request->user()->tenant_id)->with(['product:id,name,sku', 'warehouse:id,name', 'supplier:id,name']);

        foreach (['status', 'type', 'product_id', 'warehouse_id', 'mrp_run_id'] as $filter) {
            $query->when($request->filled($filter), fn ($q) => $q->where($filter, $request->input($filter)));
        }
        $query->when(! $request->filled('status'), fn ($q) => $q->whereIn('status', ['proposed', 'dismissed', 'converted']));

        $this->applyFilters($request, $query, ['product.name', 'product.sku']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Type', 'Product', 'Cartons', 'Start', 'Due', 'Supplier', 'Status'],
            fn (MrpSuggestion $s) => [$s->type, $s->product?->name, $s->qty_cartons, $s->start_date->toDateString(), $s->due_date->toDateString(), $s->supplier?->name, $s->status],
            'mrp-suggestions',
        )) {
            return $export;
        }

        $page = $query->paginate($request->integer('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($s) => $this->suggestionData($s))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function showSuggestion(Request $request, MrpSuggestion $suggestion)
    {
        abort_unless($suggestion->tenant_id === $request->user()->tenant_id, 404);

        return response()->json(['data' => $this->suggestionData($suggestion->load(['product:id,name,sku', 'warehouse:id,name', 'supplier:id,name']))]);
    }

    /**
     * A manufacture suggestion becomes a draft manufacturing order. Needs production-orders.create too.
     */
    public function convert(Request $request, MrpSuggestion $suggestion)
    {
        abort_unless($suggestion->tenant_id === $request->user()->tenant_id, 404);
        abort_unless($request->user()->can('production-orders.create'), 403);

        $order = $this->conversion->convertManufacture($suggestion, $this->actor($request));

        return (new ProductionOrderResource($order->load(['bom.product', 'warehouse', 'createdBy'])))->response()->setStatusCode(201);
    }

    /**
     * Purchase suggestions become draft purchase orders, one per supplier. Needs purchase-orders.create too.
     */
    public function convertPurchases(Request $request)
    {
        abort_unless($request->user()->can('purchase-orders.create'), 403);

        $data = $request->validate(['suggestion_ids' => ['required', 'array', 'min:1', 'max:200'], 'suggestion_ids.*' => ['string', 'distinct']]);

        $orders = $this->conversion->convertPurchases($request->user()->tenant_id, $data['suggestion_ids'], $this->actor($request));

        return response()->json(['data' => collect($orders)->map(fn ($o) => [
            'id' => $o->id, 'supplier_id' => $o->supplier_id, 'warehouse_id' => $o->warehouse_id, 'status' => $o->status,
            'lines' => $o->lines->map(fn ($l) => ['product_id' => $l->product_id, 'qty_cartons' => $l->qty_cartons])->values(),
        ])->values()], 201);
    }

    public function dismiss(Request $request, MrpSuggestion $suggestion)
    {
        abort_unless($suggestion->tenant_id === $request->user()->tenant_id, 404);

        return response()->json(['data' => $this->suggestionData($this->conversion->dismiss($suggestion))]);
    }

    private function resolveRun(Request $request): MrpRun
    {
        $query = MrpRun::query()->where('tenant_id', $request->user()->tenant_id)->where('status', 'completed');

        return ($request->filled('run_id') ? $query->whereKey($request->input('run_id')) : $query->orderByDesc('finished_at')->orderByDesc('id'))->first()
            ?? throw new ManufacturingRuleException('There is no finished planning run yet. Run planning first.');
    }

    /**
     * @return array<string, mixed>
     */
    private function runData(MrpRun $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'inputs' => $r->inputs, 'stats' => $r->stats, 'error' => $r->error,
            'started_at' => $r->started_at, 'finished_at' => $r->finished_at,
            'requested_by' => $r->requestedBy ? ['id' => $r->requestedBy->id, 'name' => $r->requestedBy->name] : null,
            'created_at' => $r->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineData(MrpRunLine $l): array
    {
        return [
            'product' => $l->product ? ['id' => $l->product->id, 'name' => $l->product->name, 'sku' => $l->product->sku] : null,
            'warehouse' => $l->warehouse ? ['id' => $l->warehouse->id, 'name' => $l->warehouse->name] : null,
            'level' => $l->level,
            'gross_requirement' => $l->gross_requirement,
            'on_hand' => $l->on_hand,
            'reserved' => $l->reserved,
            'available' => max(0, $l->on_hand - $l->reserved),
            'incoming' => $l->incoming,
            'safety_stock' => $l->safety_stock,
            'net_requirement' => $l->net_requirement,
            'planned_qty' => $l->planned_qty,
            'due_date' => $l->due_date?->toDateString(),
            'action' => $l->action,
            'sources' => $l->sources,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestionData(MrpSuggestion $s): array
    {
        return [
            'id' => $s->id, 'type' => $s->type, 'status' => $s->status,
            'product' => $s->relationLoaded('product') && $s->product ? ['id' => $s->product->id, 'name' => $s->product->name, 'sku' => $s->product->sku] : ['id' => $s->product_id],
            'warehouse' => $s->relationLoaded('warehouse') && $s->warehouse ? ['id' => $s->warehouse->id, 'name' => $s->warehouse->name] : ['id' => $s->warehouse_id],
            'supplier' => $s->relationLoaded('supplier') && $s->supplier ? ['id' => $s->supplier->id, 'name' => $s->supplier->name] : null,
            'qty_cartons' => $s->qty_cartons,
            'start_date' => $s->start_date->toDateString(), 'due_date' => $s->due_date->toDateString(),
            'sources' => $s->sources, 'mrp_run_id' => $s->mrp_run_id,
            'converted_type' => $s->converted_type, 'converted_id' => $s->converted_id,
        ];
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return ['actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip()];
    }
}

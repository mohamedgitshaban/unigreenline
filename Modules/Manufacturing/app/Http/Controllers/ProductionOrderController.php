<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Manufacturing\Http\Requests\StoreProductionOrderRequest;
use Modules\Manufacturing\Http\Requests\UpdateProductionOrderRequest;
use Modules\Manufacturing\Http\Resources\ProductionOrderResource;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Services\ManageProductionOrderService;
use Modules\Manufacturing\Services\ProductionCostingService;
use Modules\Manufacturing\Services\ProductionOrderLifecycleService;
use Modules\Manufacturing\Services\ProductionRecordingService;
use Modules\Manufacturing\Services\SalesDemandOrderService;
use Modules\Sales\Models\SalesOrder;

class ProductionOrderController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = ['bom.product', 'bomRevision', 'warehouse', 'assignee', 'components.product', 'workOrders', 'outputs', 'consumptions', 'createdBy', 'completedBy'];

    private const LIST_RELATIONS = ['bom.product', 'warehouse', 'assignee', 'createdBy', 'completedBy'];

    public function __construct(
        private readonly ManageProductionOrderService $manageOrder,
        private readonly ProductionOrderLifecycleService $lifecycle,
        private readonly ProductionRecordingService $recording,
        private readonly ProductionCostingService $costing,
        private readonly SalesDemandOrderService $salesDemand,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ProductionOrder::class);

        $query = ProductionOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::LIST_RELATIONS);

        foreach (['status', 'priority', 'warehouse_id', 'bom_id', 'assigned_to', 'source_type', 'source_id'] as $filter) {
            $query->when($request->filled($filter), fn ($q) => $q->where($filter, $request->input($filter)));
        }
        $query->when($request->filled('product_id'), fn ($q) => $q->whereHas('bom', fn ($b) => $b->where('product_id', $request->input('product_id'))));
        $query->when($request->filled('planned_from'), fn ($q) => $q->whereDate('planned_start', '>=', $request->input('planned_from')));
        $query->when($request->filled('planned_to'), fn ($q) => $q->whereDate('planned_start', '<=', $request->input('planned_to')));

        $this->applyFilters($request, $query, ['id', 'number', 'batch_no', 'notes', 'bom.name', 'bom.code', 'warehouse.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Number', 'Date', 'BOM', 'Warehouse', 'Status', 'Priority', 'Planned Cartons', 'Produced Cartons', 'Rejected Cartons', 'Batch', 'Material Cost', 'Conversion Cost', 'Cost / Carton'],
            fn (ProductionOrder $o) => [
                $o->number ?? $o->id, $o->production_date->toDateString(), $o->bom?->name, $o->warehouse?->name, $o->status, $o->priority,
                $o->output_qty_cartons, $o->produced_qty_cartons, $o->rejected_qty_cartons, $o->batch_no, $o->material_cost, $o->conversion_cost, $o->cost_per_carton,
            ],
            'production-orders',
        )) {
            return $export;
        }

        return ProductionOrderResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);
        $this->authorize('view', $productionOrder);

        return new ProductionOrderResource($productionOrder->load(self::RELATIONS));
    }

    public function store(StoreProductionOrderRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['created_by'] = $request->user()->id;

        $order = $this->manageOrder->create($data, $this->actor($request));

        return (new ProductionOrderResource($order->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(UpdateProductionOrderRequest $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        $order = $this->manageOrder->update($productionOrder, $request->validated(), $this->actor($request));

        return new ProductionOrderResource($order->load(self::RELATIONS));
    }

    public function destroy(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);
        $this->authorize('delete', $productionOrder);

        $this->manageOrder->delete($productionOrder, $this->actor($request));

        return response()->noContent();
    }

    /**
     * Make-to-order: raises draft orders for the manufactured products on an open sales order that stock does not cover.
     * Needs the right to view that sales order as well.
     */
    public function fromSalesOrder(Request $request)
    {
        $data = $request->validate(['sales_order_id' => ['required', 'string']]);

        // Asking for production against a sales order needs the right to see sales orders, checked before anything is looked up.
        abort_unless($request->user()->can('sales-orders.view'), 403);

        $salesOrder = SalesOrder::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($data['sales_order_id']);

        $result = $this->salesDemand->raise($salesOrder, $this->actor($request));

        return ProductionOrderResource::collection(collect($result['created'])->map(fn ($o) => $o->load(self::LIST_RELATIONS)))
            ->additional(['skipped' => $result['skipped']])->response()->setStatusCode(201);
    }

    /**
     * Draft → confirmed: fixes the components and cost estimate and, by default, reserves the materials.
     */
    public function confirm(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        return $this->resource($this->lifecycle->confirm($productionOrder, $this->actor($request)));
    }

    /**
     * Confirmed → planned: creates and schedules the work orders.
     */
    public function plan(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        return $this->resource($this->lifecycle->plan($productionOrder, $this->actor($request)));
    }

    public function reserveMaterials(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        $result = $this->lifecycle->reserveMaterials($productionOrder, $this->actor($request));

        return $this->resource($result['order'])->additional(['shortages' => $result['shortages']]);
    }

    public function releaseMaterials(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        return $this->resource($this->lifecycle->releaseMaterials($productionOrder, $this->actor($request)));
    }

    public function start(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        return $this->resource($this->lifecycle->start($productionOrder, $this->actor($request)));
    }

    public function pause(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->resource($this->lifecycle->pause($productionOrder, $this->actor($request), $data['reason'] ?? null));
    }

    public function resume(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        return $this->resource($this->lifecycle->resume($productionOrder, $this->actor($request)));
    }

    /**
     * Receives finished goods (and by-products), issuing the materials for them first when backflushing.
     * Safe to retry with an `Idempotency-Key` header.
     */
    public function recordProduction(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        $data = $request->validate([
            'qty' => ['required_without:rejected', 'integer', 'min:0', 'max:10000000'],
            'rejected' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'batch_no' => ['nullable', 'string', 'max:255'],
            'exp_date' => ['nullable', 'date', 'after:today'],
            'mfg_date' => ['nullable', 'date'],
            'reject_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->recording->recordProduction($productionOrder, $data + ['idempotency_key' => $this->key($request, $data)], $this->actor($request));

        return $this->resource($result['order'])->additional(['replayed' => $result['replayed']]);
    }

    /**
     * Issues materials by hand (for manual consumption, optional components, or extra material).
     */
    public function consumeMaterials(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.component_id' => ['required_without:lines.*.product_id', 'nullable', 'string'],
            'lines.*.product_id' => ['required_without:lines.*.component_id', 'nullable', 'string'],
            'lines.*.cartons' => ['required', 'integer', 'min:1', 'max:10000000'],
            'lines.*.work_order_id' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->recording->consumeMaterials($productionOrder, $data + ['idempotency_key' => $this->key($request, $data)], $this->actor($request));

        return $this->resource($result['order'])->additional(['replayed' => $result['replayed']]);
    }

    /**
     * Records material wasted in production, or output that failed. May wait for approval.
     */
    public function recordScrap(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(['component', 'finished'])],
            'product_id' => ['required_if:kind,component', 'nullable', 'string'],
            'cartons' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'max:255'],
            'work_order_id' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->recording->recordScrap($productionOrder, $data + ['idempotency_key' => $this->key($request, $data)], $this->actor($request));

        return $this->resource($result['order'])->additional([
            'scrap' => ['id' => $result['scrap']->id, 'number' => $result['scrap']->number, 'status' => $result['scrap']->status, 'scrap_cost' => $result['scrap']->scrap_cost],
            'replayed' => $result['replayed'],
        ]);
    }

    /**
     * Closes the order: makes what is left (unless told not to), settles cost, and deals with any shortfall.
     */
    public function complete(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);
        $this->authorize('approve', $productionOrder);

        $data = $request->validate([
            'produce_remaining' => ['sometimes', 'boolean'],
            'create_backorder' => ['nullable', 'boolean'],
            'batch_no' => ['nullable', 'string', 'max:255'],
            'exp_date' => ['nullable', 'date', 'after:today'],
            'mfg_date' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->lifecycle->complete($productionOrder, $data + ['idempotency_key' => $this->key($request, $data)], $this->actor($request));

        return $this->resource($result['order'])->additional([
            'backorder' => $result['backorder'] ? ['id' => $result['backorder']->id, 'number' => $result['backorder']->number, 'output_qty_cartons' => $result['backorder']->output_qty_cartons] : null,
            'replayed' => $result['replayed'],
        ]);
    }

    public function cancel(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);
        $this->authorize('update', $productionOrder);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->resource($this->lifecycle->cancel($productionOrder, $data['reason'] ?? null, $this->actor($request)));
    }

    public function reopen(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        return $this->resource($this->lifecycle->reopen($productionOrder, $this->actor($request)));
    }

    public function createBackorder(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        $backorder = $this->lifecycle->createBackorder($productionOrder, $this->actor($request));

        return (new ProductionOrderResource($backorder->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * Planned against actual cost, category by category, with the ledger entries behind it.
     */
    public function costs(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        $entryIds = JournalEntry::query()->where('tenant_id', $productionOrder->tenant_id)->where('ref', $productionOrder->id)->orderBy('created_at')->orderBy('id')->get(['id', 'description', 'entry_date', 'created_at']);

        return response()->json(['data' => $this->costing->breakdown($productionOrder) + [
            'order_id' => $productionOrder->id,
            'number' => $productionOrder->number,
            'status' => $productionOrder->status,
            'wip_accounting' => $productionOrder->wip_accounting,
            'journal_entries' => $entryIds->map(fn ($e) => ['id' => $e->id, 'description' => $e->description, 'entry_date' => $e->entry_date, 'lines' => $e->lines()->get(['account_code', 'debit', 'credit'])->toArray()])->values(),
        ]]);
    }

    /**
     * Which batches of which materials went into this order, and which batches it made (with what is left of each).
     */
    public function traceability(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        $productionOrder->load(['consumptions.product', 'outputs.product']);
        $batchStock = InventoryBatch::query()->whereIn('id', $productionOrder->outputs->pluck('batch_id')->filter())->pluck('qty_cartons', 'id');

        return response()->json(['data' => [
            'order_id' => $productionOrder->id,
            'number' => $productionOrder->number,
            'consumed' => $productionOrder->consumptions->map(fn ($c) => [
                'kind' => $c->kind,
                'product' => ['id' => $c->product_id, 'name' => $c->product?->name, 'sku' => $c->product?->sku],
                'batch_id' => $c->batch_id,
                'batch_no' => $c->batch_no,
                'qty_cartons' => $c->qty_cartons,
                'at' => $c->created_at,
            ])->values(),
            'produced' => $productionOrder->outputs->map(fn ($o) => [
                'kind' => $o->kind,
                'product' => ['id' => $o->product_id, 'name' => $o->product?->name, 'sku' => $o->product?->sku],
                'batch_id' => $o->batch_id,
                'batch_no' => $o->batch_no,
                'qty_cartons' => $o->qty_cartons,
                'qty_on_hand' => $o->batch_id ? ($batchStock[$o->batch_id] ?? 0) : null,
                'at' => $o->created_at,
            ])->values(),
            'source' => ['type' => $productionOrder->source_type, 'id' => $productionOrder->source_id, 'parent_order_id' => $productionOrder->parent_order_id],
        ]]);
    }

    /**
     * The order's immutable history: what happened, when, and who did it.
     */
    public function history(Request $request, ProductionOrder $productionOrder)
    {
        $this->owned($request, $productionOrder);

        $events = $productionOrder->events()->getQuery()->with('user')->paginate($request->integer('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $events->getCollection()->map(fn ($e) => [
                'id' => $e->id, 'action' => $e->action, 'from_status' => $e->from_status, 'to_status' => $e->to_status,
                'user' => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name] : null,
                'payload' => $e->payload, 'at' => $e->created_at,
            ])->values(),
            'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'per_page' => $events->perPage(), 'total' => $events->total()],
        ]);
    }

    private function owned(Request $request, ProductionOrder $order): void
    {
        abort_unless($order->tenant_id === $request->user()->tenant_id, 404);
    }

    private function resource(ProductionOrder $order): ProductionOrderResource
    {
        return new ProductionOrderResource($order->load(self::RELATIONS));
    }

    /**
     * The idempotency key: the `Idempotency-Key` header, or the body field.
     *
     * @param  array<string, mixed>  $data
     */
    private function key(Request $request, array $data): ?string
    {
        return $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ];
    }
}

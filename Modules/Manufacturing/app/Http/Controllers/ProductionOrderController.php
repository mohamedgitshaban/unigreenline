<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Http\Requests\StoreProductionOrderRequest;
use Modules\Manufacturing\Http\Requests\UpdateProductionOrderRequest;
use Modules\Manufacturing\Http\Resources\ProductionOrderResource;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Services\CompleteProductionOrderService;
use Modules\Manufacturing\Services\ManageProductionOrderService;

class ProductionOrderController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = ['bom', 'warehouse', 'consumptions', 'createdBy', 'completedBy'];

    public function __construct(
        private readonly ManageProductionOrderService $manageOrder,
        private readonly CompleteProductionOrderService $completeOrder,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ProductionOrder::class);

        $query = ProductionOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'batch_no', 'notes', 'bom.name', 'warehouse.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, ['manufacturing.export', 'accounting.export'], $query,
            ['ID', 'Date', 'BOM', 'Warehouse', 'Status', 'Runs', 'Output Cartons', 'Batch', 'Material Cost', 'Conversion Cost', 'Cost / Carton'],
            fn (ProductionOrder $o) => [
                $o->id, $o->production_date->toDateString(), $o->bom?->name, $o->warehouse?->name, $o->status, $o->runs,
                $o->output_qty_cartons, $o->batch_no, $o->material_cost, $o->conversion_cost, $o->cost_per_carton,
            ],
            'production-orders',
        )) {
            return $export;
        }

        return ProductionOrderResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, ProductionOrder $productionOrder)
    {
        abort_unless($productionOrder->tenant_id === $request->user()->tenant_id, 404);

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
        abort_unless($productionOrder->tenant_id === $request->user()->tenant_id, 404);

        $order = $this->manageOrder->update($productionOrder, $request->validated(), $this->actor($request));

        return new ProductionOrderResource($order->load(self::RELATIONS));
    }

    public function destroy(Request $request, ProductionOrder $productionOrder)
    {
        abort_unless($productionOrder->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('delete', $productionOrder);

        $this->manageOrder->delete($productionOrder, $this->actor($request));

        return response()->noContent();
    }

    /**
     * Consumes the components, books the finished batch and posts the cost.
     */
    public function complete(Request $request, ProductionOrder $productionOrder)
    {
        abort_unless($productionOrder->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('approve', $productionOrder);

        $order = $this->completeOrder->complete($productionOrder, $this->actor($request));

        return new ProductionOrderResource($order->load(self::RELATIONS));
    }

    public function cancel(Request $request, ProductionOrder $productionOrder)
    {
        abort_unless($productionOrder->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('update', $productionOrder);

        $order = $this->manageOrder->cancel($productionOrder, $this->actor($request));

        return new ProductionOrderResource($order->load(self::RELATIONS));
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

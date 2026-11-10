<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Inventory\Http\Requests\StoreStockRequestRequest;
use Modules\Inventory\Http\Resources\StockRequestResource;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockRequest;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockRequestService;
use Modules\Inventory\Services\UnitConverter;
use Modules\Inventory\Support\WarehouseAccess;

/**
 * Warehouses asking for stock: from another warehouse, from Purchasing or from Manufacturing.
 */
class StockRequestController extends Controller
{
    use Filterable, Sortable;

    private const RELATIONS = ['warehouse', 'fromWarehouse', 'product', 'requester', 'decider'];

    /** What a user needs, besides the stock-request permission, to grant a request to each source. */
    private const GRANT_PERMISSION = ['purchasing' => 'purchase-orders.create', 'manufacturing' => 'production-orders.create'];

    public function __construct(private readonly StockRequestService $requests, private readonly WarehouseAccess $access, private readonly UnitConverter $units) {}

    /**
     * Requests the caller can see: those for or from a warehouse they work in, plus the ones addressed to
     * Purchasing or Manufacturing if they can raise those documents.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', StockRequest::class);

        $query = StockRequest::query()->where('tenant_id', $request->user()->tenant_id)->with(self::RELATIONS);
        $visible = $this->access->idsFor($request->user());

        if ($visible !== null) {
            $sources = collect(self::GRANT_PERMISSION)->filter(fn (string $permission) => $request->user()->can($permission))->keys()->all();

            $query->where(fn ($q) => $q->whereIn('warehouse_id', $visible)->orWhereIn('from_warehouse_id', $visible)->orWhereIn('source', $sources));
        }

        $this->applyFilters($request, $query, ['id', 'number', 'notes', 'product.name', 'warehouse.name']);
        $this->applySorting($request, $query);

        return StockRequestResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, string $id)
    {
        return new StockRequestResource($this->findVisible($request, $id));
    }

    public function store(StoreStockRequestRequest $request)
    {
        $data = $request->validated();

        if (filled($data['unit_id'] ?? null)) {
            // Asked for in a packaging unit: keep what was asked and work out the whole cartons it comes to.
            $data['unit_qty'] = $data['qty'];
            // Stock is held in whole cartons, so a few packs still ask for a whole carton (the same rule as selling packs).
            $product = Product::query()->with('units')->findOrFail($data['product_id']);
            $data['qty_cartons'] = (int) ceil($data['qty'] * $this->units->baseQty($product, $data['unit_id']) / $product->carton_qty);
        }

        unset($data['qty']);

        $stockRequest = $this->requests->create(
            $data + ['tenant_id' => $request->user()->tenant_id, 'requested_by' => $request->user()->id],
            ['actor_name' => $request->user()->name, 'ip_address' => $request->ip()],
        );

        return (new StockRequestResource($stockRequest->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * Grants a request: the stock is transferred, or a draft purchase order or production order is raised.
     */
    public function approve(Request $request, string $id)
    {
        $stockRequest = $this->findVisible($request, $id);
        $this->mayDecide($request, $stockRequest);

        $options = $request->validate([
            'supplier_id' => ['nullable', 'string'],
            'bom_id' => ['nullable', 'string'],
        ]);

        $granted = $this->requests->grant($stockRequest, $options, $this->actor($request));

        return new StockRequestResource($granted->load(self::RELATIONS));
    }

    public function reject(Request $request, string $id)
    {
        $stockRequest = $this->findVisible($request, $id);
        $this->mayDecide($request, $stockRequest);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new StockRequestResource($this->requests->reject($stockRequest, $data['reason'], $this->actor($request))->load(self::RELATIONS));
    }

    /**
     * The person who asked, or anyone who may grant it, withdraws an open request.
     */
    public function cancel(Request $request, string $id)
    {
        $stockRequest = $this->findVisible($request, $id);

        abort_unless($stockRequest->requested_by === $request->user()->id || $this->canDecide($request, $stockRequest), 403);

        return new StockRequestResource($this->requests->cancel($stockRequest, $this->actor($request))->load(self::RELATIONS));
    }

    private function findVisible(Request $request, string $id): StockRequest
    {
        $stockRequest = StockRequest::query()->where('tenant_id', $request->user()->tenant_id)->with(self::RELATIONS)->findOrFail($id);

        $this->authorize('view', $stockRequest);

        return $stockRequest;
    }

    private function mayDecide(Request $request, StockRequest $stockRequest): void
    {
        abort_unless($this->canDecide($request, $stockRequest), 403, 'You cannot decide this request.');
    }

    /**
     * Whoever works in the warehouse being asked may grant a warehouse request; Purchasing and Manufacturing
     * requests go to people who can raise a purchase order or a production order.
     */
    private function canDecide(Request $request, StockRequest $stockRequest): bool
    {
        if ($stockRequest->source === 'warehouse') {
            return $stockRequest->from_warehouse_id !== null && Warehouse::query()->find($stockRequest->from_warehouse_id)?->isVisibleTo($request->user()) === true;
        }

        return $request->user()->can(self::GRANT_PERMISSION[$stockRequest->source]);
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return ['actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip()];
    }
}

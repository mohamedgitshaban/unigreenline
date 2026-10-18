<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Http\Resources\ProductionScrapResource;
use Modules\Manufacturing\Models\ProductionScrap;
use Modules\Manufacturing\Services\ProductionRecordingService;

class ProductionScrapController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = ['productionOrder', 'product', 'requestedBy', 'decidedBy'];

    public function __construct(private readonly ProductionRecordingService $recording) {}

    public function index(Request $request)
    {
        $query = ProductionScrap::query()->where('tenant_id', $request->user()->tenant_id)->with(self::RELATIONS);

        foreach (['status', 'kind', 'product_id', 'production_order_id'] as $filter) {
            $query->when($request->filled($filter), fn ($q) => $q->where($filter, $request->input($filter)));
        }
        $query->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')));
        $query->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')));

        $this->applyFilters($request, $query, ['number', 'reason', 'product.name', 'productionOrder.number']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Number', 'Order', 'Kind', 'Product', 'Cartons', 'Reason', 'Status', 'Cost'],
            fn (ProductionScrap $s) => [$s->number, $s->productionOrder?->number, $s->kind, $s->product?->name, $s->qty_cartons, $s->reason, $s->status, $s->scrap_cost],
            'production-scraps',
        )) {
            return $export;
        }

        return ProductionScrapResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, ProductionScrap $productionScrap)
    {
        abort_unless($productionScrap->tenant_id === $request->user()->tenant_id, 404);

        return new ProductionScrapResource($productionScrap->load(self::RELATIONS));
    }

    public function approve(Request $request, ProductionScrap $productionScrap)
    {
        abort_unless($productionScrap->tenant_id === $request->user()->tenant_id, 404);

        return new ProductionScrapResource($this->recording->decideScrap($productionScrap, true, $this->actor($request))->load(self::RELATIONS));
    }

    public function reject(Request $request, ProductionScrap $productionScrap)
    {
        abort_unless($productionScrap->tenant_id === $request->user()->tenant_id, 404);

        return new ProductionScrapResource($this->recording->decideScrap($productionScrap, false, $this->actor($request))->load(self::RELATIONS));
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return ['actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip()];
    }
}

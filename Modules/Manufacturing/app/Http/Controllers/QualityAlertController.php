<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Models\QualityAlert;
use Modules\Manufacturing\Services\QualityService;

class QualityAlertController extends Controller
{
    use Filterable, Sortable;

    public function __construct(private readonly QualityService $quality) {}

    public function index(Request $request)
    {
        $query = QualityAlert::query()->where('tenant_id', $request->user()->tenant_id)->with(['check:id,name,status', 'productionOrder:id,number', 'product:id,name,sku']);

        foreach (['status', 'severity', 'production_order_id', 'product_id'] as $filter) {
            $query->when($request->filled($filter), fn ($q) => $q->where($filter, $request->input($filter)));
        }

        $this->applyFilters($request, $query, ['description', 'corrective_action']);
        $this->applySorting($request, $query);

        $page = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($a) => $this->data($a))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function resolve(Request $request, QualityAlert $qualityAlert)
    {
        abort_unless($qualityAlert->tenant_id === $request->user()->tenant_id, 404);

        $data = $request->validate(['corrective_action' => ['nullable', 'string', 'max:2000']]);

        $alert = $this->quality->resolveAlert($qualityAlert, $data, ['actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip()]);

        return response()->json(['data' => $this->data($alert->load(['check:id,name,status', 'productionOrder:id,number', 'product:id,name,sku']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(QualityAlert $a): array
    {
        return [
            'id' => $a->id,
            'check' => $a->check ? ['id' => $a->check->id, 'name' => $a->check->name, 'status' => $a->check->status] : null,
            'production_order' => $a->productionOrder ? ['id' => $a->productionOrder->id, 'number' => $a->productionOrder->number] : null,
            'product' => $a->product ? ['id' => $a->product->id, 'name' => $a->product->name, 'sku' => $a->product->sku] : null,
            'severity' => $a->severity, 'description' => $a->description, 'corrective_action' => $a->corrective_action,
            'status' => $a->status, 'resolved_at' => $a->resolved_at, 'created_at' => $a->created_at,
        ];
    }
}

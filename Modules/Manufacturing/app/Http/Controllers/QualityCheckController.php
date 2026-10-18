<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Models\QualityAlert;
use Modules\Manufacturing\Models\QualityCheck;
use Modules\Manufacturing\Services\QualityService;

class QualityCheckController extends Controller
{
    use Filterable, Sortable;

    public function __construct(private readonly QualityService $quality) {}

    public function index(Request $request)
    {
        $query = QualityCheck::query()->where('tenant_id', $request->user()->tenant_id)->with(['productionOrder:id,number', 'workOrder:id,number', 'product:id,name,sku']);

        foreach (['status', 'point', 'production_order_id', 'work_order_id', 'product_id', 'mandatory'] as $filter) {
            $query->when($request->filled($filter), fn ($q) => $q->where($filter, $request->input($filter)));
        }

        $this->applyFilters($request, $query, ['name', 'defect_class', 'notes']);
        $this->applySorting($request, $query);

        $page = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($c) => $this->data($c))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, QualityCheck $qualityCheck)
    {
        abort_unless($qualityCheck->tenant_id === $request->user()->tenant_id, 404);

        return response()->json(['data' => $this->data($qualityCheck->load(['productionOrder:id,number', 'workOrder:id,number', 'product:id,name,sku', 'alerts']))]);
    }

    /**
     * Raises an inspection by hand, e.g. of incoming material before it is used.
     */
    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'definition_id' => ['nullable', 'string', Rule::exists('quality_check_definitions', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required_without:definition_id', 'nullable', 'string', 'max:255'],
            'point' => ['sometimes', Rule::in(['incoming', 'in_process', 'final'])],
            'type' => ['sometimes', Rule::in(['pass_fail', 'measure'])],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric'],
            'mandatory' => ['sometimes', 'boolean'],
            'production_order_id' => ['nullable', 'string', Rule::exists('production_orders', 'id')->where('tenant_id', $tenantId)],
            'work_order_id' => ['nullable', 'string', Rule::exists('work_orders', 'id')->where('tenant_id', $tenantId)],
            'product_id' => ['nullable', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'reference_type' => ['nullable', 'string', 'max:40'],
            'reference_id' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json(['data' => $this->data($this->quality->create($tenantId, $data))], 201);
    }

    /**
     * Enters the result: pass/fail, or a measured value checked against the limits. A failure opens an alert.
     */
    public function record(Request $request, QualityCheck $qualityCheck)
    {
        abort_unless($qualityCheck->tenant_id === $request->user()->tenant_id, 404);

        $data = $request->validate([
            'result' => ['nullable', Rule::in(['passed', 'failed'])],
            'measured_value' => ['nullable', 'numeric'],
            'defect_class' => ['nullable', 'string', 'max:255'],
            'rejected_cartons' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'severity' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
        ]);

        $check = $this->quality->record($qualityCheck, $data, ['actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip()]);

        return response()->json(['data' => $this->data($check->load('alerts'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(QualityCheck $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'point' => $c->point, 'type' => $c->type, 'mandatory' => $c->mandatory,
            'min_value' => $c->min_value, 'max_value' => $c->max_value,
            'production_order' => $c->relationLoaded('productionOrder') && $c->productionOrder ? ['id' => $c->productionOrder->id, 'number' => $c->productionOrder->number] : null,
            'work_order' => $c->relationLoaded('workOrder') && $c->workOrder ? ['id' => $c->workOrder->id, 'number' => $c->workOrder->number] : null,
            'product' => $c->relationLoaded('product') && $c->product ? ['id' => $c->product->id, 'name' => $c->product->name, 'sku' => $c->product->sku] : null,
            'reference_type' => $c->reference_type, 'reference_id' => $c->reference_id,
            'status' => $c->status, 'measured_value' => $c->measured_value, 'defect_class' => $c->defect_class,
            'rejected_cartons' => $c->rejected_cartons, 'notes' => $c->notes, 'checked_at' => $c->checked_at,
            'alerts' => $c->relationLoaded('alerts') ? $c->alerts->map(fn (QualityAlert $a) => ['id' => $a->id, 'severity' => $a->severity, 'status' => $a->status, 'description' => $a->description])->values() : null,
        ];
    }
}

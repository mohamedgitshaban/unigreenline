<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Models\ProductPlanning;

/**
 * How material planning treats each product: made or bought, lead time, lot-sizing rule, make-to-order.
 * One row per product; saving a product that already has one updates it.
 */
class ProductPlanningController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    public function index(Request $request)
    {
        $query = ProductPlanning::query()->where('tenant_id', $request->user()->tenant_id)->with('product:id,name,sku,supplier_id,min_stock_cartons,reorder_level');
        $query->when($request->filled('procurement'), fn ($q) => $q->where('procurement', $request->input('procurement')));
        $query->when($request->filled('lot_rule'), fn ($q) => $q->where('lot_rule', $request->input('lot_rule')));

        $this->applyFilters($request, $query, ['product.name', 'product.sku']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['SKU', 'Product', 'Procurement', 'Lead time (days)', 'Lot rule', 'Lot / min / max / multiple', 'Make to order'],
            fn (ProductPlanning $p) => [$p->product?->sku, $p->product?->name, $p->procurement ?? 'auto', $p->lead_time_days, $p->lot_rule, implode(' / ', [$p->lot_qty, $p->min_qty, $p->max_qty, $p->multiple_qty]), $p->make_to_order ? 'Yes' : 'No'],
            'product-planning',
        )) {
            return $export;
        }

        $page = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($p) => $this->data($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, ProductPlanning $planning)
    {
        $this->owned($request, $planning);

        return response()->json(['data' => $this->data($planning->load('product:id,name,sku,supplier_id,min_stock_cartons,reorder_level'))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request, true));
        $this->assertLotRule($data);

        $existing = ProductPlanning::query()->where('tenant_id', $request->user()->tenant_id)->where('product_id', $data['product_id'])->first();

        if ($existing !== null) {
            $this->updateRecord($request, $existing, $data, 'manufacturing', 'ProductPlanning');

            return response()->json(['data' => $this->data($existing->load('product:id,name,sku,supplier_id,min_stock_cartons,reorder_level'))]);
        }

        $planning = ProductPlanning::create($data + ['tenant_id' => $request->user()->tenant_id]);
        $this->recordAudit($request, 'manufacturing', 'ProductPlanning', $planning, 'INSERT', null, ['product_id' => $planning->product_id, 'lot_rule' => $planning->lot_rule]);

        return response()->json(['data' => $this->data($planning->load('product:id,name,sku,supplier_id,min_stock_cartons,reorder_level'))], 201);
    }

    public function update(Request $request, ProductPlanning $planning)
    {
        $this->owned($request, $planning);
        $data = $request->validate($this->rules($request, false));
        $this->assertLotRule($data + $planning->only(['lot_rule', 'lot_qty', 'min_qty', 'max_qty', 'multiple_qty']));
        $this->updateRecord($request, $planning, $data, 'manufacturing', 'ProductPlanning');

        return response()->json(['data' => $this->data($planning->load('product:id,name,sku,supplier_id,min_stock_cartons,reorder_level'))]);
    }

    public function destroy(Request $request, ProductPlanning $planning)
    {
        return $this->destroyRecord($request, $planning, 'manufacturing', 'ProductPlanning');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, bool $create): array
    {
        $tenantId = $request->user()->tenant_id;

        return [
            'product_id' => $create ? ['required', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)] : ['prohibited'],
            'procurement' => ['nullable', Rule::in(['make', 'buy'])],
            'lead_time_days' => ['sometimes', 'integer', 'between:0,365'],
            'lot_rule' => ['sometimes', Rule::in(['exact', 'fixed', 'min_max', 'multiple'])],
            'lot_qty' => ['nullable', 'integer', 'min:1'],
            'min_qty' => ['nullable', 'integer', 'min:1'],
            'max_qty' => ['nullable', 'integer', 'min:1'],
            'multiple_qty' => ['nullable', 'integer', 'min:1'],
            'make_to_order' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Each rule needs its own quantity.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertLotRule(array $data): void
    {
        $rule = $data['lot_rule'] ?? 'exact';
        $needs = ['fixed' => 'lot_qty', 'multiple' => 'multiple_qty', 'min_max' => 'min_qty'];

        if (isset($needs[$rule]) && empty($data[$needs[$rule]])) {
            throw ValidationException::withMessages([$needs[$rule] => "The {$rule} lot rule needs {$needs[$rule]}."]);
        }

        if (! empty($data['min_qty']) && ! empty($data['max_qty']) && $data['max_qty'] < $data['min_qty']) {
            throw ValidationException::withMessages(['max_qty' => 'The maximum cannot be below the minimum.']);
        }
    }

    private function owned(Request $request, ProductPlanning $planning): void
    {
        abort_unless($planning->tenant_id === $request->user()->tenant_id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ProductPlanning $p): array
    {
        return [
            'id' => $p->id,
            'product' => $p->product ? [
                'id' => $p->product->id, 'name' => $p->product->name, 'sku' => $p->product->sku, 'supplier_id' => $p->product->supplier_id,
                'safety_stock_cartons' => $p->product->min_stock_cartons, 'reorder_point_cartons' => $p->product->reorder_level,
            ] : null,
            'procurement' => $p->procurement,
            'lead_time_days' => $p->lead_time_days,
            'lot_rule' => $p->lot_rule,
            'lot_qty' => $p->lot_qty, 'min_qty' => $p->min_qty, 'max_qty' => $p->max_qty, 'multiple_qty' => $p->multiple_qty,
            'make_to_order' => $p->make_to_order,
        ];
    }
}

<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Models\MrpDemand;

/**
 * Planned requirements that are not sales orders: a need entered by hand, or a forecast.
 */
class MrpDemandController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    private const RELATIONS = ['product:id,name,sku', 'warehouse:id,name'];

    public function index(Request $request)
    {
        $query = MrpDemand::query()->where('tenant_id', $request->user()->tenant_id)->with(self::RELATIONS);

        foreach (['status', 'kind', 'product_id', 'warehouse_id'] as $filter) {
            $query->when($request->filled($filter), fn ($q) => $q->where($filter, $request->input($filter)));
        }
        $query->when($request->filled('from'), fn ($q) => $q->whereDate('due_date', '>=', $request->input('from')));
        $query->when($request->filled('to'), fn ($q) => $q->whereDate('due_date', '<=', $request->input('to')));

        $this->applyFilters($request, $query, ['notes', 'product.name', 'product.sku']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Product', 'Warehouse', 'Cartons', 'Due', 'Kind', 'Status'],
            fn (MrpDemand $d) => [$d->product?->name, $d->warehouse?->name, $d->qty_cartons, $d->due_date->toDateString(), $d->kind, $d->status],
            'mrp-demands',
        )) {
            return $export;
        }

        $page = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($d) => $this->data($d))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, MrpDemand $demand)
    {
        $this->owned($request, $demand);

        return response()->json(['data' => $this->data($demand->load(self::RELATIONS))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request, true));

        $demand = MrpDemand::create($data + ['tenant_id' => $request->user()->tenant_id, 'created_by' => $request->user()->id]);
        $this->recordAudit($request, 'manufacturing', 'MrpDemand', $demand, 'INSERT', null, ['product_id' => $demand->product_id, 'qty_cartons' => (string) $demand->qty_cartons]);

        return response()->json(['data' => $this->data($demand->load(self::RELATIONS))], 201);
    }

    public function update(Request $request, MrpDemand $demand)
    {
        $this->owned($request, $demand);
        $this->updateRecord($request, $demand, $request->validate($this->rules($request, false)), 'manufacturing', 'MrpDemand');

        return response()->json(['data' => $this->data($demand->load(self::RELATIONS))]);
    }

    public function destroy(Request $request, MrpDemand $demand)
    {
        return $this->destroyRecord($request, $demand, 'manufacturing', 'MrpDemand');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, bool $create): array
    {
        $tenantId = $request->user()->tenant_id;
        $presence = $create ? 'required' : 'sometimes';

        return [
            'product_id' => [$presence, 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => [$presence, 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'qty_cartons' => [$presence, 'integer', 'min:1', 'max:10000000'],
            'due_date' => [$presence, 'date'],
            'kind' => ['sometimes', Rule::in(['manual', 'forecast'])],
            'status' => ['sometimes', Rule::in(['open', 'fulfilled', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function owned(Request $request, MrpDemand $demand): void
    {
        abort_unless($demand->tenant_id === $request->user()->tenant_id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(MrpDemand $d): array
    {
        return [
            'id' => $d->id,
            'product' => $d->product ? ['id' => $d->product->id, 'name' => $d->product->name, 'sku' => $d->product->sku] : null,
            'warehouse' => $d->warehouse ? ['id' => $d->warehouse->id, 'name' => $d->warehouse->name] : null,
            'qty_cartons' => $d->qty_cartons, 'due_date' => $d->due_date->toDateString(), 'kind' => $d->kind, 'status' => $d->status, 'notes' => $d->notes,
        ];
    }
}

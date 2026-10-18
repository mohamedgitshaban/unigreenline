<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Models\QualityCheckDefinition;

class QualityCheckDefinitionController extends Controller
{
    use Filterable, ManagesRecords, Sortable;

    public function index(Request $request)
    {
        $query = QualityCheckDefinition::query()->where('tenant_id', $request->user()->tenant_id)->with('product:id,name,sku');
        $query->when($request->filled('point'), fn ($q) => $q->where('point', $request->input('point')));
        $query->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')));

        $this->applyFilters($request, $query, ['name', 'instructions', 'product.name']);
        $this->applySorting($request, $query);

        $page = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($d) => $this->data($d))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, QualityCheckDefinition $definition)
    {
        abort_unless($definition->tenant_id === $request->user()->tenant_id, 404);

        return response()->json(['data' => $this->data($definition->load('product:id,name,sku'))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request, true));
        $this->assertLimits($data);

        $definition = QualityCheckDefinition::create($data + ['tenant_id' => $request->user()->tenant_id]);
        $this->recordAudit($request, 'manufacturing', 'QualityCheckDefinition', $definition, 'INSERT', null, ['name' => $definition->name, 'point' => $definition->point]);

        return response()->json(['data' => $this->data($definition->load('product:id,name,sku'))], 201);
    }

    public function update(Request $request, QualityCheckDefinition $definition)
    {
        abort_unless($definition->tenant_id === $request->user()->tenant_id, 404);

        $data = $request->validate($this->rules($request, false));
        $this->assertLimits($data + ['min_value' => $definition->min_value, 'max_value' => $definition->max_value]);
        $this->updateRecord($request, $definition, $data, 'manufacturing', 'QualityCheckDefinition');

        return response()->json(['data' => $this->data($definition->load('product:id,name,sku'))]);
    }

    public function destroy(Request $request, QualityCheckDefinition $definition)
    {
        return $this->destroyRecord($request, $definition, 'manufacturing', 'QualityCheckDefinition');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, bool $create): array
    {
        $tenantId = $request->user()->tenant_id;
        $presence = $create ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'point' => ['sometimes', Rule::in(['incoming', 'in_process', 'final'])],
            'type' => ['sometimes', Rule::in(['pass_fail', 'measure'])],
            'product_id' => ['nullable', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'routing_operation_id' => ['nullable', 'string', Rule::exists('routing_operations', 'id')],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric'],
            'unit_label' => ['nullable', 'string', 'max:30'],
            'mandatory' => ['sometimes', 'boolean'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertLimits(array $data): void
    {
        if (isset($data['min_value'], $data['max_value']) && (float) $data['min_value'] > (float) $data['max_value']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['max_value' => 'The maximum cannot be below the minimum.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function data(QualityCheckDefinition $d): array
    {
        return [
            'id' => $d->id, 'name' => $d->name, 'point' => $d->point, 'type' => $d->type,
            'product' => $d->product ? ['id' => $d->product->id, 'name' => $d->product->name, 'sku' => $d->product->sku] : null,
            'routing_operation_id' => $d->routing_operation_id,
            'min_value' => $d->min_value, 'max_value' => $d->max_value, 'unit_label' => $d->unit_label,
            'mandatory' => $d->mandatory, 'instructions' => $d->instructions, 'active' => $d->active,
        ];
    }
}

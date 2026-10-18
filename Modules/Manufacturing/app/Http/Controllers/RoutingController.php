<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Http\Requests\RoutingRules;
use Modules\Manufacturing\Http\Requests\StoreRoutingRequest;
use Modules\Manufacturing\Http\Requests\UpdateRoutingRequest;
use Modules\Manufacturing\Http\Resources\RoutingResource;
use Modules\Manufacturing\Models\Routing;
use Modules\Manufacturing\Models\RoutingOperation;
use Modules\Manufacturing\Services\RoutingService;

class RoutingController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    private const RELATIONS = ['operations.workCenter'];

    public function __construct(private readonly RoutingService $routings) {}

    public function index(Request $request)
    {
        $query = Routing::query()->where('tenant_id', $request->user()->tenant_id)->with(self::RELATIONS);
        $query->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')));

        $this->applyFilters($request, $query, ['code', 'name', 'notes']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Code', 'Name', 'Operations', 'Active'],
            fn (Routing $r) => [$r->code, $r->name, $r->operations->count(), $r->active ? 'Yes' : 'No'],
            'routings',
        )) {
            return $export;
        }

        return RoutingResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, Routing $routing)
    {
        $this->owned($request, $routing);

        return new RoutingResource($routing->load(self::RELATIONS));
    }

    public function store(StoreRoutingRequest $request)
    {
        $routing = $this->routings->create($request->validated() + ['tenant_id' => $request->user()->tenant_id]);
        $this->recordAudit($request, 'manufacturing', 'Routing', $routing, 'INSERT', null, ['code' => $routing->code, 'operations' => (string) $routing->operations->count()]);

        return (new RoutingResource($routing->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(UpdateRoutingRequest $request, Routing $routing)
    {
        $this->owned($request, $routing);
        $this->updateRecord($request, $routing, $request->validated(), 'manufacturing', 'Routing');

        return new RoutingResource($routing->load(self::RELATIONS));
    }

    /**
     * A routing that recipes use cannot be deleted (409); switch it off instead.
     */
    public function destroy(Request $request, Routing $routing)
    {
        return $this->destroyRecord($request, $routing, 'manufacturing', 'Routing');
    }

    public function storeOperation(Request $request, Routing $routing)
    {
        $this->owned($request, $routing);

        $data = $request->validate(RoutingRules::operation($request->user()->tenant_id));
        $operation = $this->routings->addOperation($routing, $data);
        $this->recordAudit($request, 'manufacturing', 'RoutingOperation', $operation, 'INSERT', null, ['routing' => $routing->code, 'sequence' => (string) $operation->sequence]);

        return response()->json(['data' => $this->operationData($operation)], 201);
    }

    public function updateOperation(Request $request, Routing $routing, RoutingOperation $operation)
    {
        $this->owned($request, $routing);
        abort_unless($operation->routing_id === $routing->id, 404);

        $data = $request->validate(RoutingRules::operation($request->user()->tenant_id, required: false));
        $operation = $this->routings->updateOperation($operation, $data);
        $this->recordAudit($request, 'manufacturing', 'RoutingOperation', $operation, 'UPDATE', null, $data ? array_map('strval', array_filter($data, 'is_scalar')) : null);

        return response()->json(['data' => $this->operationData($operation)]);
    }

    public function destroyOperation(Request $request, Routing $routing, RoutingOperation $operation)
    {
        $this->owned($request, $routing);
        abort_unless($operation->routing_id === $routing->id, 404);

        $this->routings->deleteOperation($operation);
        $this->recordAudit($request, 'manufacturing', 'RoutingOperation', $operation, 'DELETE', ['routing' => $routing->code, 'sequence' => (string) $operation->sequence], null);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function operationData(RoutingOperation $o): array
    {
        return [
            'id' => $o->id, 'sequence' => $o->sequence, 'name' => $o->name,
            'work_center' => $o->workCenter ? ['id' => $o->workCenter->id, 'code' => $o->workCenter->code, 'name' => $o->workCenter->name] : null,
            'work_center_id' => $o->work_center_id, 'setup_minutes' => $o->setup_minutes, 'run_minutes' => $o->run_minutes,
            'cleanup_minutes' => $o->cleanup_minutes, 'depends_on_id' => $o->depends_on_id, 'instructions' => $o->instructions,
        ];
    }

    private function owned(Request $request, Routing $routing): void
    {
        abort_unless($routing->tenant_id === $request->user()->tenant_id, 404);
    }
}

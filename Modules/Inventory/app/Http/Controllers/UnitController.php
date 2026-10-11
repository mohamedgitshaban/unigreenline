<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Inventory\Http\Requests\StoreUnitRequest;
use Modules\Inventory\Http\Requests\UpdateUnitRequest;
use Modules\Inventory\Http\Resources\UnitResource;
use Modules\Inventory\Models\Unit;

/**
 * The packaging unit names (Packet, Package, Carton, ...) an admin can add
 * to. A unit that some product is packaged in can't be deleted (409) —
 * set `active: false` to stop offering it instead.
 */
class UnitController extends Controller
{
    use Filterable, ManagesRecords, Sortable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Unit::class);

        $query = Unit::query()->where('tenant_id', $request->user()->tenant_id);

        $this->applyFilters($request, $query, ['name']);
        $this->applySorting($request, $query);

        return UnitResource::collection($query->paginate($request->integer('per_page', 50))->withQueryString());
    }

    public function show(Request $request, Unit $unit)
    {
        abort_unless($unit->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('view', $unit);

        return new UnitResource($unit);
    }

    public function store(StoreUnitRequest $request)
    {
        $unit = Unit::create($request->validated() + ['tenant_id' => $request->user()->tenant_id]);

        $this->recordAudit($request, 'inventory', 'Unit', $unit, 'INSERT', null, $unit->only(['name', 'active']));

        return (new UnitResource($unit))->response()->setStatusCode(201);
    }

    public function update(UpdateUnitRequest $request, Unit $unit)
    {
        $this->updateRecord($request, $unit, $request->validated(), 'inventory', 'Unit');

        return new UnitResource($unit);
    }

    public function destroy(Request $request, Unit $unit)
    {
        return $this->destroyRecord($request, $unit, 'inventory', 'Unit');
    }
}

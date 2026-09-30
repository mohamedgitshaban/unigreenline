<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Http\Requests\StoreWarehouseRequest;
use Modules\Inventory\Http\Requests\UpdateWarehouseRequest;
use Modules\Inventory\Http\Resources\WarehouseResource;
use Modules\Inventory\Models\Warehouse;

class WarehouseController extends Controller
{
    use Exportable, Sortable;

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Warehouse::class);

        $query = Warehouse::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->visibleTo($request->user());

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'inventory.export', $query,
            ['ID', 'Name', 'City', 'Governorate', 'Temperature', 'Capacity', 'Status', 'Stock Value'],
            fn (Warehouse $w) => [$w->id, $w->name, $w->city, $w->governorate, $w->temperature, $w->capacity, $w->status, $w->stock_value],
            'warehouses',
        )) {
            return $export;
        }

        $warehouses = $query->paginate($request->integer('per_page', 15));

        return WarehouseResource::collection($warehouses);
    }

    public function show(Warehouse $warehouse)
    {
        $this->authorize('view', $warehouse);

        return new WarehouseResource($warehouse->load('batches'));
    }

    public function store(StoreWarehouseRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        if (! empty($data['manager_id'])) {
            $data['manager_name'] = User::find($data['manager_id'])?->name;
        }

        $warehouse = Warehouse::create($data);

        $this->auditLog->record([
            'module' => 'inventory',
            'entity_type' => 'Warehouse',
            'entity_id' => $warehouse->id,
            'operation' => 'INSERT',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $request->user()->tenant_id,
            'new_values' => $warehouse->only(['name', 'city', 'status']),
            'ip_address' => $request->ip(),
        ]);

        return new WarehouseResource($warehouse);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse)
    {
        $data = $request->validated();

        if (array_key_exists('manager_id', $data)) {
            $data['manager_name'] = $data['manager_id'] ? User::find($data['manager_id'])?->name : null;
        }

        $prevValues = $warehouse->only(array_keys($data));
        $warehouse->update($data);

        $this->auditLog->record([
            'module' => 'inventory',
            'entity_type' => 'Warehouse',
            'entity_id' => $warehouse->id,
            'operation' => 'UPDATE',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $request->user()->tenant_id,
            'prev_values' => $prevValues,
            'new_values' => $warehouse->only(array_keys($data)),
            'ip_address' => $request->ip(),
        ]);

        return new WarehouseResource($warehouse);
    }
}

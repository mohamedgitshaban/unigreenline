<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ImportsSpreadsheets;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Http\Requests\StoreWarehouseRequest;
use Modules\Inventory\Http\Requests\UpdateWarehouseRequest;
use Modules\Inventory\Http\Resources\InventoryBatchResource;
use Modules\Inventory\Http\Resources\WarehouseResource;
use Modules\Inventory\Imports\WarehouseImport;
use Modules\Inventory\Models\Warehouse;

class WarehouseController extends Controller
{
    use Exportable, Filterable, ImportsSpreadsheets, ManagesRecords, Sortable;

    private const RELATIONS = [
        'manager',
        'manager.roles.permissions',
        'manager.permissions',
    ];

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Warehouse::class);

        $query = Warehouse::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->visibleTo($request->user());

        $query->with(self::RELATIONS)->withCount('batches');

        $this->applyFilters($request, $query, ['name', 'city', 'governorate', 'address', 'manager_name', 'phone']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Name', 'City', 'Governorate', 'Temperature', 'Capacity', 'Status', 'Stock Value'],
            fn (Warehouse $w) => [$w->id, $w->name, $w->city, $w->governorate, $w->temperature, $w->capacity, $w->status, $w->stock_value],
            'warehouses',
        )) {
            return $export;
        }

        $warehouses = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return WarehouseResource::collection($warehouses);
    }

    public function show(Request $request, string $id)
    {
        $warehouse = $this->findForTenant($request, $id);

        $this->authorize('view', $warehouse);

        return new WarehouseResource($warehouse);
    }

    /**
     * The warehouse's batches as their own paginated list.
     */
    public function batches(Request $request, string $id)
    {
        $warehouse = Warehouse::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $warehouse);

        $query = $warehouse->batches()->getQuery()->with([
            'product',
            'product.category',
            'product.supplier',
        ]);

        $this->applyFilters($request, $query, ['batch_no', 'product.name', 'product.sku']);

        $this->applySorting($request, $query);

        $batches = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return InventoryBatchResource::collection($batches);
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

        return (new WarehouseResource($this->findForTenant($request, $warehouse->id)))->response()->setStatusCode(201);
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

        return new WarehouseResource($this->findForTenant($request, $warehouse->id));
    }

    private function findForTenant(Request $request, string $id): Warehouse
    {
        return Warehouse::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->withCount('batches')
            ->findOrFail($id);
    }

    public function destroy(Request $request, Warehouse $warehouse)
    {
        return $this->destroyRecord($request, $warehouse, 'inventory', 'Warehouse');
    }

    public function import(Request $request)
    {
        $this->authorize('create', Warehouse::class);

        return $this->runImport($request, new WarehouseImport($request->user()));
    }

    public function importTemplate(Request $request)
    {
        $this->authorize('create', Warehouse::class);

        return $this->templateResponse(new WarehouseImport($request->user()), 'warehouses');
    }
}

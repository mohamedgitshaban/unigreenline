<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Printing\DocumentPrinter;
use Modules\Inventory\Http\Requests\StoreTransferRequest;
use Modules\Inventory\Http\Resources\TransferResource;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Printing\TransferDocument;
use Modules\Inventory\Services\TransferStockService;

class TransferController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = [
        'product',
        'product.category',
        'product.supplier',
        'fromWarehouse',
        'fromWarehouse.manager',
        'fromWarehouse.manager.roles.permissions',
        'fromWarehouse.manager.permissions',
        'toWarehouse',
        'toWarehouse.manager',
        'toWarehouse.manager.roles.permissions',
        'toWarehouse.manager.permissions',
        'createdBy',
        'createdBy.roles.permissions',
        'createdBy.permissions',
    ];

    public function __construct(private readonly TransferStockService $transferStock) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Transfer::class);

        $query = Transfer::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $query->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'batch_no', 'notes', 'product.name', 'fromWarehouse.name', 'toWarehouse.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Product', 'From Warehouse', 'To Warehouse', 'Batch No', 'Qty Cartons', 'Transfer Date', 'Status'],
            fn (Transfer $t) => [$t->id, $t->product_id, $t->from_warehouse_id, $t->to_warehouse_id, $t->batch_no, $t->qty_cartons, $t->transfer_date, $t->status],
            'transfers',
        )) {
            return $export;
        }

        $transfers = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return TransferResource::collection($transfers);
    }

    public function show(Request $request, string $id)
    {
        $transfer = Transfer::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->findOrFail($id);

        $this->authorize('view', $transfer);

        return new TransferResource($transfer);
    }

    /**
     * The transfer slip ready to print (GET /transfers/{id}/print) — no prices.
     */
    public function print(Request $request, string $id, TransferDocument $document, DocumentPrinter $printer)
    {
        $transfer = Transfer::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($id);

        $this->authorize('view', $transfer);

        return $printer->respond($request, $document->build($transfer));
    }

    public function store(StoreTransferRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['created_by'] = $request->user()->id;
        $data['actor_id'] = $request->user()->id;
        $data['actor_name'] = $request->user()->name;
        $data['ip_address'] = $request->ip();

        $transfer = $this->transferStock->transfer($data);

        return (new TransferResource($transfer))->response()->setStatusCode(201);
    }
}

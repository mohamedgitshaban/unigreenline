<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Sales\Http\Requests\StoreInvoiceReturnRequest;
use Modules\Sales\Http\Requests\StoreReturnRequest;
use Modules\Sales\Http\Resources\ReturnRecordResource;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\ReturnRecord;
use Modules\Sales\Services\ProcessInvoiceReturnService;
use Modules\Sales\Services\ProcessReturnService;

class ReturnController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = [
        'invoice',
        'invoice.salesOrder',
        'invoice.salesOrder.customer',
        'invoice.salesOrder.customer.salesRep',
        'invoice.salesOrder.customer.salesRep.roles.permissions',
        'invoice.salesOrder.customer.salesRep.permissions',
        'invoice.salesOrder.warehouse',
        'invoice.salesOrder.warehouse.manager',
        'invoice.salesOrder.warehouse.manager.roles.permissions',
        'invoice.salesOrder.warehouse.manager.permissions',
        'invoice.salesOrder.salesRep',
        'invoice.salesOrder.salesRep.roles.permissions',
        'invoice.salesOrder.salesRep.permissions',
        'invoice.customer',
        'invoice.customer.salesRep',
        'invoice.customer.salesRep.roles.permissions',
        'invoice.customer.salesRep.permissions',
        'customer',
        'customer.salesRep',
        'customer.salesRep.roles.permissions',
        'customer.salesRep.permissions',
        'supplier',
        'product',
        'product.category',
        'product.supplier',
        'warehouse',
        'warehouse.manager',
        'warehouse.manager.roles.permissions',
        'warehouse.manager.permissions',
    ];

    public function __construct(private readonly ProcessReturnService $processReturn) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ReturnRecord::class);

        $query = ReturnRecord::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $query->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'number', 'batch_no', 'reason', 'product.name', 'customer.name', 'supplier.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Type', 'Product', 'Warehouse', 'Batch No', 'Qty', 'Unit', 'Amount', 'Restocked', 'Return Date'],
            fn (ReturnRecord $r) => [$r->id, $r->type, $r->product_id, $r->warehouse_id, $r->batch_no, $r->qty, $r->unit, $r->amount, $r->restocked ? 'Yes' : 'No', $r->return_date->toDateString()],
            'returns',
        )) {
            return $export;
        }

        $returns = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return ReturnRecordResource::collection($returns);
    }

    public function show(Request $request, string $id)
    {
        $returnRecord = ReturnRecord::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->findOrFail($id);

        $this->authorize('view', $returnRecord);

        return new ReturnRecordResource($returnRecord);
    }

    public function store(StoreReturnRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['actor_id'] = $request->user()->id;
        $data['actor_name'] = $request->user()->name;
        $data['ip_address'] = $request->ip();

        $return = $this->processReturn->process($data);

        return (new ReturnRecordResource($return))->response()->setStatusCode(201);
    }

    /**
     * An invoice's returns as a paginated list (GET /invoices/{id}/returns).
     */
    public function forInvoice(Request $request, string $id)
    {
        $invoice = Invoice::query()->where('tenant_id', $request->user()->tenant_id)->with('salesOrder')->findOrFail($id);

        $this->authorize('view', $invoice);

        $query = ReturnRecord::query()->where('invoice_id', $invoice->id)->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'number', 'batch_no', 'reason', 'product.name']);
        $this->applySorting($request, $query);

        return ReturnRecordResource::collection($query->paginate($request->integer('per_page', 10))->withQueryString());
    }

    /**
     * A return made from an invoice: the lines ticked and how many of each come back, priced from the invoice
     * itself (POST /invoices/{id}/returns).
     */
    public function storeForInvoice(StoreInvoiceReturnRequest $request, string $id, ProcessInvoiceReturnService $invoiceReturn)
    {
        $invoice = Invoice::query()->where('tenant_id', $request->user()->tenant_id)->with('salesOrder')->findOrFail($id);

        $this->authorize('view', $invoice);

        $returns = $invoiceReturn->handle($invoice, $request->validated() + [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return ReturnRecordResource::collection(collect($returns)->map->load(self::RELATIONS))->response()->setStatusCode(201);
    }
}

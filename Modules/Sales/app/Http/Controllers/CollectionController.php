<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Printing\DocumentPrinter;
use Modules\Core\Support\DataScope;
use Modules\Sales\Http\Requests\StoreCollectionRequest;
use Modules\Sales\Http\Resources\CollectionResource;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Printing\SalesDocuments;
use Modules\Sales\Services\CreateCollectionService;

class CollectionController extends Controller
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
        'collectedBy',
        'collectedBy.roles.permissions',
        'collectedBy.permissions',
    ];

    public function __construct(private readonly CreateCollectionService $createCollection, private readonly DataScope $scope) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Collection::class);

        $query = Collection::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $this->scope->constrainThrough($query, $request->user(), 'invoice.salesOrder', 'sales_rep_id');

        $query->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'reference', 'notes', 'invoice_id', 'customer.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Invoice', 'Amount', 'Method', 'Reference', 'Payment Date', 'Notes'],
            fn (Collection $c) => [$c->id, $c->invoice_id, $c->amount, $c->method, $c->reference, $c->payment_date->toDateString(), $c->notes],
            'collections',
        )) {
            return $export;
        }

        $collections = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return CollectionResource::collection($collections);
    }

    public function store(StoreCollectionRequest $request)
    {
        $data = $request->validated();
        $data['collected_by'] = $request->user()->id;
        $data['actor_id'] = $request->user()->id;
        $data['actor_name'] = $request->user()->name;
        $data['ip_address'] = $request->ip();

        $collection = $this->createCollection->create($data);

        return (new CollectionResource($collection))->response()->setStatusCode(201);
    }

    /**
     * An invoice's collections as a paginated list (GET /invoices/{id}/collections).
     */
    public function forInvoice(Request $request, string $id)
    {
        $invoice = Invoice::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('salesOrder')
            ->findOrFail($id);

        $this->authorize('view', $invoice);

        $query = Collection::query()
            ->where('invoice_id', $invoice->id)
            ->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'reference', 'notes']);

        $this->applySorting($request, $query);

        $collections = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return CollectionResource::collection($collections);
    }

    public function show(Request $request, string $id)
    {
        $collection = Collection::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->findOrFail($id);

        $this->authorize('view', $collection);

        return new CollectionResource($collection);
    }

    /**
     * The payment receipt ready to print (GET /collections/{id}/print).
     */
    public function print(Request $request, string $id, SalesDocuments $documents, DocumentPrinter $printer)
    {
        $collection = Collection::query()->where('tenant_id', $request->user()->tenant_id)->with('invoice.salesOrder')->findOrFail($id);

        $this->authorize('view', $collection);

        return $printer->respond($request, $documents->collection($collection));
    }
}

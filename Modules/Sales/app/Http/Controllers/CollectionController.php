<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Sales\Http\Requests\StoreCollectionRequest;
use Modules\Sales\Http\Resources\CollectionResource;
use Modules\Sales\Models\Collection;
use Modules\Sales\Services\CreateCollectionService;

class CollectionController extends Controller
{
    use Exportable, Filterable, Sortable;

    public function __construct(private readonly CreateCollectionService $createCollection) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Collection::class);

        $query = Collection::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when(
                $request->user()->hasRole('Sales Rep'),
                fn ($query) => $query->whereHas(
                    'invoice.salesOrder',
                    fn ($q) => $q->where('sales_rep_id', $request->user()->id)
                )
            );

        $query->with([
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
        ]);

        $this->applyFilters($request, $query, ['id', 'reference', 'notes', 'invoice_id', 'customer.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, ['sales.export', 'accounting.export'], $query,
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
}

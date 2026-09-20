<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sales\Http\Requests\StoreCollectionRequest;
use Modules\Sales\Http\Resources\CollectionResource;
use Modules\Sales\Models\Collection;
use Modules\Sales\Services\CreateCollectionService;

class CollectionController extends Controller
{
    public function __construct(private readonly CreateCollectionService $createCollection) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Collection::class);

        $collections = Collection::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when(
                $request->user()->hasRole('Sales Rep'),
                fn ($query) => $query->whereHas(
                    'invoice.salesOrder',
                    fn ($q) => $q->where('sales_rep_id', $request->user()->id)
                )
            )
            ->latest('payment_date')
            ->paginate($request->integer('per_page', 15));

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

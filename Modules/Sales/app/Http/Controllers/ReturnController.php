<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Sales\Http\Requests\StoreReturnRequest;
use Modules\Sales\Http\Resources\ReturnRecordResource;
use Modules\Sales\Models\ReturnRecord;
use Modules\Sales\Services\ProcessReturnService;

class ReturnController extends Controller
{
    use Exportable, Sortable;

    public function __construct(private readonly ProcessReturnService $processReturn) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ReturnRecord::class);

        $query = ReturnRecord::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, ['sales.export', 'purchasing.export'], $query,
            ['ID', 'Type', 'Product', 'Warehouse', 'Batch No', 'Qty', 'Unit', 'Amount', 'Restocked', 'Return Date'],
            fn (ReturnRecord $r) => [$r->id, $r->type, $r->product_id, $r->warehouse_id, $r->batch_no, $r->qty, $r->unit, $r->amount, $r->restocked ? 'Yes' : 'No', $r->return_date->toDateString()],
            'returns',
        )) {
            return $export;
        }

        $returns = $query->paginate($request->integer('per_page', 15));

        return ReturnRecordResource::collection($returns);
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
}

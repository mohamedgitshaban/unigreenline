<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sales\Http\Requests\StoreReturnRequest;
use Modules\Sales\Http\Resources\ReturnRecordResource;
use Modules\Sales\Models\ReturnRecord;
use Modules\Sales\Services\ProcessReturnService;

class ReturnController extends Controller
{
    public function __construct(private readonly ProcessReturnService $processReturn) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ReturnRecord::class);

        $returns = ReturnRecord::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest('return_date')
            ->paginate($request->integer('per_page', 15));

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

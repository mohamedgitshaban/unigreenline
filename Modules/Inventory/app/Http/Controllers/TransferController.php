<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Requests\StoreTransferRequest;
use Modules\Inventory\Http\Resources\TransferResource;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Services\TransferStockService;

class TransferController extends Controller
{
    public function __construct(private readonly TransferStockService $transferStock) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Transfer::class);

        $transfers = Transfer::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest('transfer_date')
            ->paginate($request->integer('per_page', 15));

        return TransferResource::collection($transfers);
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

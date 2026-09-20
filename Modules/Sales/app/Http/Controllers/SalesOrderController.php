<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sales\Http\Requests\StoreSalesOrderRequest;
use Modules\Sales\Http\Requests\UpdateSalesOrderStatusRequest;
use Modules\Sales\Http\Resources\SalesOrderResource;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CreateSalesOrderService;
use Modules\Sales\Services\UpdateSalesOrderStatusService;

class SalesOrderController extends Controller
{
    public function __construct(
        private readonly CreateSalesOrderService $createSalesOrder,
        private readonly UpdateSalesOrderStatusService $updateStatus,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SalesOrder::class);

        $orders = SalesOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->visibleTo($request->user())
            ->latest('order_date')
            ->paginate($request->integer('per_page', 15));

        return SalesOrderResource::collection($orders);
    }

    public function show(SalesOrder $salesOrder)
    {
        $this->authorize('view', $salesOrder);

        return new SalesOrderResource($salesOrder->load('lines'));
    }

    public function store(StoreSalesOrderRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['sales_rep_id'] ??= $request->user()->id;
        $data['actor_id'] = $request->user()->id;
        $data['actor_name'] = $request->user()->name;
        $data['ip_address'] = $request->ip();

        $order = $this->createSalesOrder->create($data);

        return (new SalesOrderResource($order))->response()->setStatusCode(201);
    }

    public function updateStatus(UpdateSalesOrderStatusRequest $request, SalesOrder $salesOrder)
    {
        $order = $this->updateStatus->transition($salesOrder, $request->validated('status'), [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return new SalesOrderResource($order);
    }
}

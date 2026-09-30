<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Purchasing\Http\Requests\ReceivePurchaseOrderRequest;
use Modules\Purchasing\Http\Requests\StorePurchaseOrderRequest;
use Modules\Purchasing\Http\Resources\PurchaseOrderResource;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Services\CreatePurchaseOrderService;
use Modules\Purchasing\Services\ReceivePurchaseOrderService;

class PurchaseOrderController extends Controller
{
    use Exportable, Sortable;

    public function __construct(
        private readonly CreatePurchaseOrderService $createPurchaseOrder,
        private readonly ReceivePurchaseOrderService $receivePurchaseOrder,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $query = PurchaseOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)->with('supplier', 'warehouse', 'lines');

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'purchasing.export', $query,
            ['ID', 'Supplier', 'Warehouse', 'Status', 'Subtotal', 'Tax', 'Total', 'Order Date', 'Expected Date', 'Received Date'],
            fn (PurchaseOrder $po) => [$po->id, $po->supplier_id, $po->warehouse_id, $po->status, $po->subtotal, $po->tax_amount, $po->total, $po->order_date->toDateString(), $po->expected_date?->toDateString(), $po->received_date?->toDateString()],
            'purchase-orders',
        )) {
            return $export;
        }

        $orders = $query->paginate($request->integer('per_page', 15));

        return PurchaseOrderResource::collection($orders);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($purchaseOrder->load('lines'));
    }

    public function store(StorePurchaseOrderRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['created_by'] = $request->user()->id;
        $data['actor_id'] = $request->user()->id;
        $data['actor_name'] = $request->user()->name;
        $data['ip_address'] = $request->ip();

        $order = $this->createPurchaseOrder->create($data);

        return (new PurchaseOrderResource($order))->response()->setStatusCode(201);
    }

    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        $order = $this->receivePurchaseOrder->receive($purchaseOrder, $request->validated('receipts'), [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return new PurchaseOrderResource($order);
    }
}

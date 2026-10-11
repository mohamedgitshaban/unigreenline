<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Purchasing\Http\Requests\PricePurchaseOrderRequest;
use Modules\Purchasing\Http\Requests\ReceivePurchaseOrderRequest;
use Modules\Purchasing\Http\Requests\StorePurchaseOrderRequest;
use Modules\Purchasing\Http\Requests\UpdatePurchaseOrderRequest;
use Modules\Purchasing\Http\Requests\UploadPurchaseOrderInvoiceRequest;
use Modules\Purchasing\Http\Resources\PurchaseOrderLineResource;
use Modules\Purchasing\Http\Resources\PurchaseOrderResource;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Services\CreatePurchaseOrderService;
use Modules\Purchasing\Services\DeletePurchaseOrderService;
use Modules\Purchasing\Services\PricePurchaseOrderService;
use Modules\Purchasing\Services\ReceivePurchaseOrderService;
use Modules\Purchasing\Services\UpdatePurchaseOrderService;

class PurchaseOrderController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = [
        'supplier',
        'warehouse',
        'warehouse.manager',
        'warehouse.manager.roles.permissions',
        'warehouse.manager.permissions',
        'createdBy',
        'createdBy.roles.permissions',
        'createdBy.permissions',
    ];

    private const LINE_RELATIONS = ['product.category', 'product.supplier'];

    public function __construct(
        private readonly CreatePurchaseOrderService $createPurchaseOrder,
        private readonly ReceivePurchaseOrderService $receivePurchaseOrder,
        private readonly DeletePurchaseOrderService $deletePurchaseOrder,
        private readonly UpdatePurchaseOrderService $updatePurchaseOrder,
        private readonly PricePurchaseOrderService $pricePurchaseOrder,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $query = PurchaseOrder::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $query->with(self::RELATIONS)->withCount('lines');

        $this->applyFilters($request, $query, ['id', 'notes', 'supplier.name', 'warehouse.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'purchasing.export', $query,
            ['ID', 'Supplier', 'Warehouse', 'Status', 'Subtotal', 'Tax', 'Total', 'Order Date', 'Expected Date', 'Received Date'],
            fn (PurchaseOrder $po) => [$po->id, $po->supplier_id, $po->warehouse_id, $po->status, $po->subtotal, $po->tax_amount, $po->total, $po->order_date->toDateString(), $po->expected_date?->toDateString(), $po->received_date?->toDateString()],
            'purchase-orders',
        )) {
            return $export;
        }

        $orders = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return PurchaseOrderResource::collection($orders);
    }

    public function show(Request $request, string $id)
    {
        $purchaseOrder = $this->findForTenant($request, $id);

        $this->authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($purchaseOrder);
    }

    /**
     * The order's lines as their own paginated list. Line ids are what
     * receive's `receipts[].line_id` refers to.
     */
    public function lines(Request $request, string $id)
    {
        $purchaseOrder = PurchaseOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $purchaseOrder);

        $query = $purchaseOrder->lines()->getQuery()->with(self::LINE_RELATIONS);

        $this->applyFilters($request, $query, ['product.name', 'product.sku']);

        $this->applySorting($request, $query);

        $lines = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return PurchaseOrderLineResource::collection($lines);
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

        return (new PurchaseOrderResource($this->findForTenant($request, $order->id)))->response()->setStatusCode(201);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        $order = $this->updatePurchaseOrder->update($purchaseOrder, $request->validated(), [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return new PurchaseOrderResource($this->findForTenant($request, $order->id));
    }

    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        $order = $this->receivePurchaseOrder->receive($purchaseOrder, $request->validated('receipts'), [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ], $request->validated('invoice_data'), $request->validated('free_gifts', []));

        return new PurchaseOrderResource($this->findForTenant($request, $order->id));
    }

    /**
     * Step one of attaching the supplier's invoice: store the file and hand
     * back its path, which the client sends as `invoice_data.file_path` on receive.
     */
    public function uploadInvoice(UploadPurchaseOrderInvoiceRequest $request)
    {
        $invoice = $request->file('invoice');
        $path = $invoice->store(PurchaseOrder::invoiceDirectory($request->user()->tenant_id));

        return response()->json(['data' => [
            'file_path' => $path,
            'original_name' => $invoice->getClientOriginalName(),
            'mime_type' => $invoice->getMimeType(),
            'size' => $invoice->getSize(),
        ]], 201);
    }

    public function downloadInvoice(Request $request, string $id)
    {
        $purchaseOrder = PurchaseOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $purchaseOrder);

        $path = $purchaseOrder->supplier_invoice_path;

        abort_if($path === null || ! Storage::exists($path), 404, 'This purchase order has no supplier invoice file.');

        return Storage::download($path, "supplier-invoice-{$purchaseOrder->id}.".pathinfo($path, PATHINFO_EXTENSION));
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder)
    {
        // Checked outside the policy: Administrator's Gate::before bypass
        // would otherwise let a destructive action cross tenants.
        abort_unless($purchaseOrder->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('delete', $purchaseOrder);

        $this->deletePurchaseOrder->delete($purchaseOrder, [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->noContent();
    }

    private function findForTenant(Request $request, string $id): PurchaseOrder
    {
        return PurchaseOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->withCount('lines')
            ->findOrFail($id);
    }

    /**
     * The administrator's work queue: orders waiting for their line costs.
     */
    public function pendingPricing(Request $request)
    {
        $this->authorize('price', PurchaseOrder::class);

        $query = PurchaseOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('status', 'pending_pricing')
            ->with(self::RELATIONS)
            ->withCount('lines');

        $this->applyFilters($request, $query, ['id', 'notes', 'supplier.name', 'warehouse.name']);

        $this->applySorting($request, $query);

        return PurchaseOrderResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    /**
     * Sets the cost of every line and releases the order from pending pricing.
     */
    public function price(PricePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        $order = $this->pricePurchaseOrder->price($purchaseOrder, $request->validated('lines'), [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return new PurchaseOrderResource($this->findForTenant($request, $order->id));
    }
}

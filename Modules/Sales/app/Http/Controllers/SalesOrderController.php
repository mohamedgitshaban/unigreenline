<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\CRM\Models\Customer;
use Modules\Sales\Http\Requests\StoreSalesOrderRequest;
use Modules\Sales\Http\Requests\UpdateSalesOrderRequest;
use Modules\Sales\Http\Requests\UpdateSalesOrderStatusRequest;
use Modules\Sales\Http\Resources\SalesOrderLineResource;
use Modules\Sales\Http\Resources\SalesOrderResource;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CreateSalesOrderService;
use Modules\Sales\Services\DeleteSalesOrderService;
use Modules\Sales\Services\UpdateSalesOrderService;
use Modules\Sales\Services\UpdateSalesOrderStatusService;

class SalesOrderController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = [
        'customer',
        'customer.salesRep',
        'customer.salesRep.roles.permissions',
        'customer.salesRep.permissions',
        'warehouse',
        'warehouse.manager',
        'warehouse.manager.roles.permissions',
        'warehouse.manager.permissions',
        'salesRep',
        'salesRep.roles.permissions',
        'salesRep.permissions',
    ];

    private const LINE_RELATIONS = ['product.category', 'product.supplier'];

    public function __construct(
        private readonly CreateSalesOrderService $createSalesOrder,
        private readonly UpdateSalesOrderStatusService $updateStatus,
        private readonly UpdateSalesOrderService $updateOrder,
        private readonly DeleteSalesOrderService $deleteOrder,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SalesOrder::class);

        $query = SalesOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->visibleTo($request->user());

        $query->with(self::RELATIONS)->withCount(['lines', 'invoices']);

        $this->applyFilters($request, $query, ['id', 'notes', 'customer_name', 'salesRep_name', 'warehouse_name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'sales.export', $query,
            ['ID', 'Customer', 'Warehouse', 'Status', 'Pay Type', 'Subtotal', 'Tax', 'Total', 'Order Date'],
            fn (SalesOrder $o) => [$o->id, $o->customer_id, $o->warehouse_id, $o->status, $o->pay_type, $o->subtotal, $o->tax_amount, $o->total, $o->order_date->toDateString()],
            'sales-orders',
        )) {
            return $export;
        }

        $orders = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return SalesOrderResource::collection($orders);
    }

    public function show(Request $request, string $id)
    {
        $salesOrder = $this->findForTenant($request, $id);

        $this->authorize('view', $salesOrder);

        return new SalesOrderResource($salesOrder);
    }

    /**
     * The order's lines as their own paginated list.
     */
    public function lines(Request $request, string $id)
    {
        $salesOrder = SalesOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $salesOrder);

        $query = $salesOrder->lines()->getQuery()->with(self::LINE_RELATIONS);

        $this->applyFilters($request, $query, ['batch_no', 'product.name', 'product.sku']);

        $this->applySorting($request, $query);

        $lines = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return SalesOrderLineResource::collection($lines);
    }

    /**
     * A customer's sales orders as a paginated list (GET /customers/{id}/orders).
     */
    public function forCustomer(Request $request, string $id)
    {
        $customer = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $customer);

        $query = SalesOrder::query()
            ->where('customer_id', $customer->id)
            ->visibleTo($request->user())
            ->with(self::RELATIONS)
            ->withCount(['lines', 'invoices']);

        $this->applyFilters($request, $query, ['id', 'notes']);

        $this->applySorting($request, $query);

        $orders = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return SalesOrderResource::collection($orders);
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

        return (new SalesOrderResource($this->findForTenant($request, $order->id)))->response()->setStatusCode(201);
    }

    public function updateStatus(UpdateSalesOrderStatusRequest $request, SalesOrder $salesOrder)
    {
        $order = $this->updateStatus->transition($salesOrder, $request->validated('status'), [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return new SalesOrderResource($this->findForTenant($request, $order->id, withDelivery: true));
    }

    /**
     * The delivery (a single record, created on `delivered`) is only
     * included in the status-transition response.
     */
    private function findForTenant(Request $request, string $id, bool $withDelivery = false): SalesOrder
    {
        $relations = self::RELATIONS;

        if ($withDelivery) {
            $relations = [
                ...$relations,
                'delivery',
                ...array_map(fn (string $relation) => "delivery.{$relation}", DeliveryController::RELATIONS),
            ];
        }

        return SalesOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with($relations)
            ->withCount(['lines', 'invoices'])
            ->findOrFail($id);
    }

    public function update(UpdateSalesOrderRequest $request, SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->tenant_id === $request->user()->tenant_id, 404);

        $order = $this->updateOrder->update($salesOrder, $request->validated(), $this->actor($request));

        return new SalesOrderResource($this->findForTenant($request, $order->id));
    }

    public function destroy(Request $request, SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('delete', $salesOrder);

        $this->deleteOrder->delete($salesOrder, $this->actor($request));

        return response()->noContent();
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ];
    }
}

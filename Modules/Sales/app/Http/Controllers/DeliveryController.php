<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Services\AuditLogService;
use Modules\Sales\Http\Requests\DeliverDeliveryRequest;
use Modules\Sales\Http\Requests\UploadDeliveryInvoiceRequest;
use Modules\Sales\Http\Resources\DeliveryResource;
use Modules\Sales\Models\Delivery;

class DeliveryController extends Controller
{
    use Exportable, Filterable, Sortable;

    public const RELATIONS = [
        'salesOrder',
        'salesOrder.customer',
        'salesOrder.customer.salesRep',
        'salesOrder.customer.salesRep.roles.permissions',
        'salesOrder.customer.salesRep.permissions',
        'salesOrder.warehouse',
        'salesOrder.warehouse.manager',
        'salesOrder.warehouse.manager.roles.permissions',
        'salesOrder.warehouse.manager.permissions',
        'salesOrder.salesRep',
        'salesOrder.salesRep.roles.permissions',
        'salesOrder.salesRep.permissions',
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
    ];

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Delivery::class);

        $query = Delivery::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when(
                $request->user()->hasRole('Sales Rep'),
                fn ($query) => $query->whereHas('salesOrder', fn ($q) => $q->where('sales_rep_id', $request->user()->id))
            );

        $query->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'driver', 'notes', 'so_id', 'invoice_id', 'customer.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Sales Order', 'Invoice', 'Customer', 'Driver', 'Delivery Date', 'Status', 'Delivered At'],
            fn (Delivery $d) => [$d->id, $d->so_id, $d->invoice_id, $d->customer_id, $d->driver, $d->delivery_date?->toDateString(), $d->status, $d->delivered_at?->toDateTimeString()],
            'deliveries',
        )) {
            return $export;
        }

        $deliveries = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return DeliveryResource::collection($deliveries);
    }

    public function show(Delivery $delivery)
    {
        $this->authorize('view', $delivery);

        return new DeliveryResource($delivery);
    }

    /**
     * Marks a delivery delivered directly — for a delivery not already
     * completed by the sales order's own status transition to "delivered".
     */
    public function deliver(DeliverDeliveryRequest $request, Delivery $delivery)
    {
        $oldInvoice = $delivery->invoice_data;

        DB::transaction(function () use ($request, $delivery) {
            $prevStatus = $delivery->status;

            $delivery->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'delivery_date' => $delivery->delivery_date ?? now()->toDateString(),
                ...($request->has('invoice_data') ? ['invoice_data' => $request->validated('invoice_data')] : []),
            ]);

            $this->auditLog->record([
                'module' => 'sales',
                'entity_type' => 'Delivery',
                'entity_id' => $delivery->id,
                'operation' => 'UPDATE',
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'tenant_id' => $request->user()->tenant_id,
                'prev_values' => ['status' => $prevStatus],
                'new_values' => ['status' => 'delivered', 'invoice_data' => $delivery->invoice_data],
                'ip_address' => $request->ip(),
            ]);
        });

        if ($oldInvoice !== null && $oldInvoice !== $delivery->invoice_data) {
            Storage::delete($oldInvoice);
        }

        return new DeliveryResource($delivery);
    }

    /**
     * Step one of attaching the delivered invoice: store the file and hand
     * back its path, which the client sends as `invoice_data` on deliver.
     */
    public function uploadInvoice(UploadDeliveryInvoiceRequest $request)
    {
        $invoice = $request->file('invoice');
        $path = $invoice->store(Delivery::invoiceDirectory($request->user()->tenant_id));

        return response()->json(['data' => [
            'invoice_data' => $path,
            'original_name' => $invoice->getClientOriginalName(),
            'mime_type' => $invoice->getMimeType(),
            'size' => $invoice->getSize(),
        ]], 201);
    }

    public function downloadInvoice(Request $request, string $id)
    {
        $delivery = Delivery::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('salesOrder')
            ->findOrFail($id);

        $this->authorize('view', $delivery);

        abort_if($delivery->invoice_data === null || ! Storage::exists($delivery->invoice_data), 404, 'This delivery has no invoice file.');

        return Storage::download($delivery->invoice_data, "delivery-invoice-{$delivery->id}.".pathinfo($delivery->invoice_data, PATHINFO_EXTENSION));
    }
}

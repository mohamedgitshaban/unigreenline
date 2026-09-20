<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Sales\Http\Resources\DeliveryResource;
use Modules\Sales\Models\Delivery;

class DeliveryController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Delivery::class);

        $deliveries = Delivery::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when(
                $request->user()->hasRole('Sales Rep'),
                fn ($query) => $query->whereHas('salesOrder', fn ($q) => $q->where('sales_rep_id', $request->user()->id))
            )
            ->latest('delivery_date')
            ->paginate($request->integer('per_page', 15));

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
    public function deliver(Request $request, Delivery $delivery)
    {
        $this->authorize('update', $delivery);

        DB::transaction(function () use ($request, $delivery) {
            $prevStatus = $delivery->status;

            $delivery->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'delivery_date' => $delivery->delivery_date ?? now()->toDateString(),
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
                'new_values' => ['status' => 'delivered'],
                'ip_address' => $request->ip(),
            ]);
        });

        return new DeliveryResource($delivery);
    }
}

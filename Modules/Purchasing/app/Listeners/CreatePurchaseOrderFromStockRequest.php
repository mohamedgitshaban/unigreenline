<?php

namespace Modules\Purchasing\Listeners;

use Illuminate\Validation\ValidationException;
use Modules\Inventory\Events\StockRequestGranted;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\CreatePurchaseOrderService;

/**
 * A warehouse's request addressed to Purchasing becomes a draft purchase order for the warehouse, with the
 * product's supplier (or the one the granter names). It carries no cost, so it waits for pricing like any
 * order a buyer raises.
 */
class CreatePurchaseOrderFromStockRequest
{
    public function __construct(private readonly CreatePurchaseOrderService $purchaseOrders) {}

    public function handle(StockRequestGranted $event): void
    {
        $request = $event->stockRequest;

        if ($request->source !== 'purchasing') {
            return;
        }

        $supplierId = $event->options['supplier_id'] ?? $request->product->supplier_id;

        if ($supplierId === null || ! Supplier::query()->where('tenant_id', $request->tenant_id)->whereKey($supplierId)->exists()) {
            throw ValidationException::withMessages(['supplier_id' => 'Say which supplier to order from. This product has none set.']);
        }

        $order = $this->purchaseOrders->create([
            'tenant_id' => $request->tenant_id,
            'supplier_id' => $supplierId,
            'warehouse_id' => $request->warehouse_id,
            'created_by' => $event->actor['actor_id'] ?? $request->requested_by,
            'expected_date' => $request->needed_by?->toDateString(),
            'notes' => "Stock request {$request->number}".($request->notes ? ": {$request->notes}" : ''),
            'lines' => [['product_id' => $request->product_id, 'qty_cartons' => $request->qty_cartons, 'cost_per_carton' => null]],
            'actor_id' => $event->actor['actor_id'] ?? null,
            'actor_name' => $event->actor['actor_name'] ?? null,
            'ip_address' => $event->actor['ip_address'] ?? null,
        ]);

        $event->resolveWith('purchase_order', $order->id);
    }
}

<?php

namespace Modules\Manufacturing\Listeners;

use Illuminate\Validation\ValidationException;
use Modules\Inventory\Events\StockRequestGranted;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Services\BomSelector;
use Modules\Manufacturing\Services\ManageProductionOrderService;

/**
 * A warehouse's request addressed to Manufacturing becomes a draft production order that makes the cartons for
 * that warehouse, from the recipe the granter names or the best approved one for the product.
 */
class CreateProductionOrderFromStockRequest
{
    public function __construct(
        private readonly BomSelector $boms,
        private readonly ManageProductionOrderService $productionOrders,
    ) {}

    public function handle(StockRequestGranted $event): void
    {
        $request = $event->stockRequest;

        if ($request->source !== 'manufacturing') {
            return;
        }

        $bom = isset($event->options['bom_id'])
            ? Bom::query()->where('tenant_id', $request->tenant_id)->find($event->options['bom_id'])
            : $this->boms->forProduct($request->tenant_id, $request->product_id);

        if ($bom === null) {
            throw ValidationException::withMessages(['bom_id' => 'No approved recipe was found for this product. Choose one.']);
        }

        $order = $this->productionOrders->create([
            'tenant_id' => $request->tenant_id,
            'bom_id' => $bom->id,
            'warehouse_id' => $request->warehouse_id,
            'qty_cartons' => $request->qty_cartons,
            'planned_finish' => $request->needed_by?->toDateString(),
            'source_type' => 'manual',
            'notes' => "Stock request {$request->number}".($request->notes ? ": {$request->notes}" : ''),
        ], $event->actor);

        $event->resolveWith('production_order', $order->id);
    }
}

<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\StockReservationService;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\CartonCalculator;

/**
 * Make-to-order: raises draft manufacturing orders for the products on an open sales order that the
 * company makes and does not already have in free stock. The orders carry the sales order as their
 * source, so a manufactured product can always be traced back to the demand that caused it, and the
 * same sales order never gets two orders for the same product.
 */
class SalesDemandOrderService
{
    public function __construct(
        private readonly BomSelector $boms,
        private readonly ManageProductionOrderService $orders,
        private readonly StockReservationService $reservations,
    ) {}

    /**
     * @param  array{actor_id?: string, actor_name?: string, ip_address?: ?string}  $actor
     * @return array{created: array<int, ProductionOrder>, skipped: array<int, array{product_id: string, reason: string}>}
     */
    public function raise(SalesOrder $salesOrder, array $actor): array
    {
        if (! in_array($salesOrder->status, ['draft', 'picking'], true) || $salesOrder->stock_deducted) {
            throw new ManufacturingRuleException("Sales order is {$salesOrder->status}; only an open order (draft or picking) can drive production.");
        }

        return DB::transaction(function () use ($salesOrder, $actor) {
            $lines = $salesOrder->lines()->where('is_gift', false)->get();
            $products = Product::query()->whereIn('id', $lines->pluck('product_id'))->get()->keyBy('id');

            // Cartons wanted per product and warehouse, over all the order's lines.
            $wanted = [];
            foreach ($lines as $line) {
                $warehouseId = $line->warehouse_id ?? $salesOrder->warehouse_id;
                $key = $line->product_id.'|'.$warehouseId;
                $wanted[$key] = ($wanted[$key] ?? 0) + CartonCalculator::cartonsNeeded($line->qty, $line->free_qty, $line->unit, $products[$line->product_id]->carton_qty, $line->base_qty);
            }

            $created = [];
            $skipped = [];

            foreach ($wanted as $key => $cartons) {
                [$productId, $warehouseId] = explode('|', $key);
                $bom = $this->boms->forProduct($salesOrder->tenant_id, $productId);

                if ($bom === null) {
                    $skipped[] = ['product_id' => $productId, 'reason' => 'no_recipe'];

                    continue;
                }

                $alreadyPlanned = ProductionOrder::query()
                    ->where('tenant_id', $salesOrder->tenant_id)->where('source_type', 'sales_order')->where('source_id', $salesOrder->id)
                    ->where('status', '!=', 'cancelled')->whereHas('bom', fn ($q) => $q->where('product_id', $productId))->exists();

                if ($alreadyPlanned) {
                    $skipped[] = ['product_id' => $productId, 'reason' => 'already_planned'];

                    continue;
                }

                $toMake = $cartons - $this->reservations->freeCartons($productId, $warehouseId);

                if ($toMake <= 0) {
                    $skipped[] = ['product_id' => $productId, 'reason' => 'covered_by_stock'];

                    continue;
                }

                $created[] = $this->orders->create([
                    'tenant_id' => $salesOrder->tenant_id,
                    'bom_id' => $bom->id,
                    'warehouse_id' => $warehouseId,
                    'qty_cartons' => $toMake,
                    'planned_start' => now(),
                    'planned_finish' => $salesOrder->order_date->isFuture() ? $salesOrder->order_date : null,
                    'source_type' => 'sales_order',
                    'source_id' => $salesOrder->id,
                    'created_by' => $actor['actor_id'] ?? null,
                ], $actor);
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }
}

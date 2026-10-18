<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\MrpSuggestion;
use Modules\Purchasing\Services\CreatePurchaseOrderService;

/**
 * Turns proposals into real documents — only when a person asks. A manufacturing suggestion becomes a
 * draft manufacturing order; purchase suggestions become draft purchase orders, one per supplier, through the
 * normal purchasing service (so a purchase order without costs waits to be priced, as every other does).
 */
class MrpConversionService
{
    public function __construct(
        private readonly ManageProductionOrderService $orders,
        private readonly CreatePurchaseOrderService $purchaseOrders,
        private readonly BomSelector $boms,
    ) {}

    /**
     * @param  array{actor_id?: string, actor_name?: string, ip_address?: ?string}  $actor
     */
    public function convertManufacture(MrpSuggestion $suggestion, array $actor): \Modules\Manufacturing\Models\ProductionOrder
    {
        return DB::transaction(function () use ($suggestion, $actor) {
            $locked = $this->lockProposed($suggestion, 'manufacture');
            $bom = $this->boms->forProduct($locked->tenant_id, $locked->product_id)
                ?? throw new ManufacturingRuleException('There is no approved recipe for this product, so it cannot be manufactured.');

            $order = $this->orders->create([
                'tenant_id' => $locked->tenant_id,
                'bom_id' => $bom->id,
                'warehouse_id' => $locked->warehouse_id,
                'qty_cartons' => $locked->qty_cartons,
                'planned_start' => $locked->start_date->toDateString(),
                'planned_finish' => $locked->due_date->toDateString(),
                'source_type' => 'mrp_suggestion',
                'source_id' => $locked->id,
                'created_by' => $actor['actor_id'] ?? null,
            ], $actor);

            $locked->update(['status' => 'converted', 'open_key' => null, 'converted_type' => 'production_order', 'converted_id' => $order->id]);

            return $order;
        });
    }

    /**
     * One draft purchase order per supplier covering the given suggestions.
     *
     * @param  array<int, string>  $suggestionIds
     * @param  array{actor_id?: string, actor_name?: string, ip_address?: ?string}  $actor
     * @return array<int, \Modules\Purchasing\Models\PurchaseOrder>
     */
    public function convertPurchases(string $tenantId, array $suggestionIds, array $actor): array
    {
        return DB::transaction(function () use ($tenantId, $suggestionIds, $actor) {
            $suggestions = MrpSuggestion::query()->where('tenant_id', $tenantId)->whereIn('id', $suggestionIds)->orderBy('id')->lockForUpdate()->with('product')->get();

            if ($suggestions->count() !== count(array_unique($suggestionIds))) {
                throw new ManufacturingRuleException('One or more of those suggestions was not found.');
            }

            foreach ($suggestions as $suggestion) {
                if ($suggestion->type !== 'purchase' || ! $suggestion->isProposed()) {
                    throw new ManufacturingRuleException('Only proposed purchase suggestions can be turned into purchase orders.', ['suggestion_id' => $suggestion->id]);
                }
            }

            $withoutSupplier = $suggestions->filter(fn ($s) => ($s->supplier_id ?? $s->product->supplier_id) === null);

            if ($withoutSupplier->isNotEmpty()) {
                throw new ManufacturingRuleException('These products have no supplier, so they cannot be ordered: set the supplier on the product first.', [
                    'products' => $withoutSupplier->map(fn ($s) => ['id' => $s->product_id, 'name' => $s->product->name])->values()->all(),
                ]);
            }

            $orders = [];

            foreach ($suggestions->groupBy(fn ($s) => ($s->supplier_id ?? $s->product->supplier_id).'|'.$s->warehouse_id) as $group) {
                $first = $group->first();

                $order = $this->purchaseOrders->create([
                    'tenant_id' => $tenantId,
                    'supplier_id' => $first->supplier_id ?? $first->product->supplier_id,
                    'warehouse_id' => $first->warehouse_id,
                    'created_by' => $actor['actor_id'] ?? null,
                    'order_date' => now()->toDateString(),
                    'expected_date' => $group->min(fn ($s) => $s->due_date->toDateString()),
                    'notes' => 'Raised from planning run suggestions: '.$group->pluck('id')->implode(', '),
                    // No costs: the order waits for an administrator to price it, like any buyer's order.
                    'lines' => $group->groupBy('product_id')->map(fn ($rows, $productId) => ['product_id' => $productId, 'qty_cartons' => (int) $rows->sum('qty_cartons')])->values()->all(),
                ] + $actor);

                foreach ($group as $suggestion) {
                    $suggestion->update(['status' => 'converted', 'open_key' => null, 'converted_type' => 'purchase_order', 'converted_id' => $order->id]);
                }

                $orders[] = $order;
            }

            return $orders;
        });
    }

    public function dismiss(MrpSuggestion $suggestion): MrpSuggestion
    {
        return DB::transaction(function () use ($suggestion) {
            $locked = $this->lockProposed($suggestion, null);
            // Keeps its open key: while the same need remains, the next run leaves it dismissed.
            $locked->update(['status' => 'dismissed']);

            return $locked;
        });
    }

    private function lockProposed(MrpSuggestion $suggestion, ?string $type): MrpSuggestion
    {
        $locked = MrpSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isProposed()) {
            throw new ManufacturingRuleException("This suggestion is {$locked->status}; only a proposed one can be acted on.");
        }

        if ($type !== null && $locked->type !== $type) {
            throw new ManufacturingRuleException("This is a {$locked->type} suggestion.");
        }

        return $locked;
    }
}

<?php

namespace Modules\Analytics\Services;

use Illuminate\Support\Collection;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Transfer;
use Modules\Manufacturing\Models\ProductionConsumption;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Purchasing\Models\PurchaseOrderLine;
use Modules\Recap\Models\RecapLine;
use Modules\Sales\Models\ReturnRecord;
use Modules\Sales\Models\SalesOrderLine;

/**
 * One product's whole story as a single date-ordered timeline: bought from a
 * supplier (purchase order raised, then received into stock), moved between
 * warehouses (transfers), used in or produced by manufacturing, sold (sales
 * orders, with their invoices), returned, and written off — plus where the
 * stock sits now. Each event carries its source document id so the client
 * can link to it.
 */
class ProductLifecycleService
{
    /** Events whose amounts are what the business paid, hidden from non-admins. */
    private const COST_EVENTS = ['purchase_ordered', 'purchase_received', 'production_consumed', 'production_output', 'write_off'];

    /**
     * @return array{product: array<string, mixed>, summary: array<string, mixed>, stock: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>}
     */
    public function build(Product $product, ?string $from = null, ?string $to = null, bool $showCost = false): array
    {
        $events = collect()
            ->concat($this->purchases($product))
            ->concat($this->transfers($product))
            ->concat($this->manufacturing($product))
            ->concat($this->sales($product))
            ->concat($this->returns($product))
            ->concat($this->writeOffs($product))
            ->filter(fn (array $e) => ($from === null || $e['date'] >= $from) && ($to === null || $e['date'] <= $to))
            ->sortBy([['date', 'asc'], ['order', 'asc']])
            ->map(fn (array $e) => array_diff_key($e, ['order' => true] + ($showCost || ! in_array($e['type'], self::COST_EVENTS, true) ? [] : ['amount' => true, 'cost_per_carton' => true])))
            ->values();

        $stock = InventoryBatch::query()
            ->where('product_id', $product->id)
            ->where('qty_cartons', '>', 0)
            ->with('warehouse:id,name')
            ->orderBy('exp_date')
            ->get();

        return [
            'product' => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'carton_qty' => $product->carton_qty],
            'summary' => [
                'purchased_cartons' => (int) $events->where('type', 'purchase_received')->sum('quantity'),
                'sold_cartons' => (int) $events->where('type', 'sale')->whereIn('status', ['invoiced', 'delivered'])->sum('quantity_cartons'),
                'transferred_cartons' => (int) $events->where('type', 'transfer')->sum('quantity'),
                'written_off_cartons' => (int) $events->where('type', 'write_off')->sum('quantity'),
                'produced_cartons' => (int) $events->where('type', 'production_output')->sum('quantity'),
                'on_hand_cartons' => (int) $stock->sum('qty_cartons'),
            ],
            'stock' => $stock->map(fn (InventoryBatch $b) => [
                'batch_id' => $b->id,
                'batch_no' => $b->batch_no,
                'warehouse' => ['id' => $b->warehouse_id, 'name' => $b->warehouse?->name],
                'qty_cartons' => $b->qty_cartons,
                'exp_date' => $b->exp_date->toDateString(),
            ] + ($showCost ? ['cost_per_carton' => $b->cost_per_carton, 'is_free_gift' => (bool) $b->is_free_gift] : ['is_free_gift' => (bool) $b->is_free_gift]))->values()->all(),
            'events' => $events->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchases(Product $product): Collection
    {
        return PurchaseOrderLine::query()
            ->where('product_id', $product->id)
            ->with('purchaseOrder.supplier:id,name', 'purchaseOrder.warehouse:id,name')
            ->get()
            ->flatMap(function (PurchaseOrderLine $line) {
                $po = $line->purchaseOrder;
                $base = [
                    'reference_id' => $po->id,
                    'supplier' => $po->supplier?->name,
                    'warehouse' => $po->warehouse?->name,
                    'quantity' => $line->qty_cartons,
                    'unit' => 'Carton',
                    'amount' => $line->total,
                    'cost_per_carton' => $line->cost_per_carton,
                ];

                $events = [$base + ['date' => $po->order_date->toDateString(), 'order' => 1, 'type' => 'purchase_ordered', 'title' => "Purchase order raised with {$po->supplier?->name}", 'status' => $po->status]];

                if ($po->stock_added) {
                    $events[] = $base + ['date' => ($po->received_date ?? $po->order_date)->toDateString(), 'order' => 2, 'type' => 'purchase_received', 'title' => "Received into {$po->warehouse?->name}", 'status' => 'received'];
                }

                return $events;
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function transfers(Product $product): Collection
    {
        return Transfer::query()
            ->where('product_id', $product->id)
            ->with('fromWarehouse:id,name', 'toWarehouse:id,name')
            ->get()
            ->map(fn (Transfer $t) => [
                'date' => $t->transfer_date->toDateString(),
                'order' => 3,
                'type' => 'transfer',
                'title' => "Transferred {$t->fromWarehouse?->name} → {$t->toWarehouse?->name}",
                'reference_id' => $t->id,
                'from_warehouse' => $t->fromWarehouse?->name,
                'to_warehouse' => $t->toWarehouse?->name,
                'batch_no' => $t->batch_no,
                'quantity' => $t->qty_cartons,
                'unit' => 'Carton',
                'status' => $t->status,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function manufacturing(Product $product): Collection
    {
        $consumed = ProductionConsumption::query()
            ->where('product_id', $product->id)
            ->whereHas('productionOrder', fn ($q) => $q->where('status', 'completed'))
            ->with('productionOrder.warehouse:id,name')
            ->get()
            ->map(fn (ProductionConsumption $c) => [
                'date' => $c->productionOrder->production_date->toDateString(),
                'order' => 4,
                'type' => 'production_consumed',
                'title' => 'Used as a component in manufacturing',
                'reference_id' => $c->production_order_id,
                'warehouse' => $c->productionOrder->warehouse?->name,
                'batch_no' => $c->batch_no,
                'quantity' => $c->qty_cartons,
                'unit' => 'Carton',
                'amount' => $c->line_value,
                'status' => 'completed',
            ]);

        $produced = ProductionOrder::query()
            ->where('status', 'completed')
            ->whereHas('bom', fn ($q) => $q->where('product_id', $product->id))
            ->with('warehouse:id,name')
            ->get()
            ->map(fn (ProductionOrder $o) => [
                'date' => $o->production_date->toDateString(),
                'order' => 4,
                'type' => 'production_output',
                'title' => 'Manufactured',
                'reference_id' => $o->id,
                'warehouse' => $o->warehouse?->name,
                'batch_no' => $o->batch_no,
                'quantity' => $o->output_qty_cartons,
                'unit' => 'Carton',
                'cost_per_carton' => $o->cost_per_carton,
                'status' => 'completed',
            ]);

        return $consumed->concat($produced);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function sales(Product $product): Collection
    {
        return SalesOrderLine::query()
            ->where('product_id', $product->id)
            ->with(['salesOrder.customer:id,name', 'salesOrder.warehouse:id,name', 'salesOrder.invoices:id,so_id', 'warehouse:id,name'])
            ->get()
            ->filter(fn (SalesOrderLine $l) => $l->salesOrder->status !== 'cancelled')
            ->map(fn (SalesOrderLine $l) => [
                'date' => $l->salesOrder->order_date->toDateString(),
                'order' => 5,
                'type' => 'sale',
                'title' => "Sold to {$l->salesOrder->customer?->name}",
                'reference_id' => $l->so_id,
                'invoice_ids' => $l->salesOrder->invoices->pluck('id')->all(),
                'customer' => $l->salesOrder->customer?->name,
                'warehouse' => ($l->warehouse ?? $l->salesOrder->warehouse)?->name,
                'quantity' => $l->qty,
                'quantity_cartons' => $l->unit === 'Carton' ? $l->qty : (int) ceil($l->qty / max(1, $product->carton_qty)),
                'unit' => $l->unit,
                'free_qty' => $l->free_qty,
                'amount' => $l->subtotal,
                'status' => $l->salesOrder->status,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function returns(Product $product): Collection
    {
        return ReturnRecord::query()
            ->where('product_id', $product->id)
            ->with('customer:id,name', 'supplier:id,name', 'warehouse:id,name')
            ->get()
            ->map(fn (ReturnRecord $r) => [
                'date' => $r->return_date->toDateString(),
                'order' => 6,
                'type' => $r->type === 'Purchase Return' ? 'purchase_return' : 'sales_return',
                'title' => $r->type.($r->customer ? " from {$r->customer->name}" : ($r->supplier ? " to {$r->supplier->name}" : '')),
                'reference_id' => $r->id,
                'warehouse' => $r->warehouse?->name,
                'batch_no' => $r->batch_no,
                'quantity' => $r->qty,
                'unit' => $r->unit,
                'amount' => $r->amount,
                'restocked' => $r->restocked,
                'status' => $r->status,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function writeOffs(Product $product): Collection
    {
        return RecapLine::query()
            ->where('product_id', $product->id)
            ->whereHas('recap', fn ($q) => $q->where('status', 'approved'))
            ->with('recap.warehouse:id,name')
            ->get()
            ->map(fn (RecapLine $l) => [
                'date' => $l->recap->recap_date->toDateString(),
                'order' => 7,
                'type' => 'write_off',
                'title' => "Written off ({$l->recap->reason})",
                'reference_id' => $l->recap_id,
                'warehouse' => $l->recap->warehouse?->name,
                'batch_no' => $l->batch_no,
                'quantity' => $l->qty_cartons,
                'unit' => 'Carton',
                'amount' => $l->line_value,
                'status' => 'approved',
            ]);
    }
}

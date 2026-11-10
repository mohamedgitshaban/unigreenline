<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\StockMovementContext;
use Modules\Sales\Exceptions\DeliveryNotCancellableException;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\ReturnRecord;
use Modules\Sales\Models\SalesOrder;

/**
 * Cancels a delivery whose goods have left the warehouse, undoing everything the sale did: the cartons go back
 * into the batches they were taken from (read off the stock ledger), the cost of goods sold is reversed, the
 * invoice is voided (reversing the revenue and the customer's balance), and the order and delivery end up
 * cancelled. Refused once money was collected or goods were returned against the invoice, since those have to
 * be dealt with first.
 */
class CancelDeliveryService
{
    public function __construct(
        private readonly VoidInvoiceService $voidInvoice,
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function cancel(Delivery $delivery, ?string $reason = null, array $actor = []): Delivery
    {
        return DB::transaction(function () use ($delivery, $reason, $actor) {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                throw new DeliveryNotCancellableException('This delivery is already cancelled.');
            }

            $order = SalesOrder::query()->whereKey($locked->so_id)->lockForUpdate()->firstOrFail();

            if (! $order->stock_deducted || ! in_array($order->status, ['invoiced', 'delivered'], true)) {
                throw new DeliveryNotCancellableException('Only a delivery whose stock has left the warehouse can be cancelled.');
            }

            $invoices = $order->invoices()->get();
            $invoiceIds = $invoices->pluck('id');

            if (Collection::query()->whereIn('invoice_id', $invoiceIds)->exists()) {
                throw new DeliveryNotCancellableException('Money was collected against this order\'s invoice. Reverse the collections first.');
            }

            if (ReturnRecord::query()->whereIn('invoice_id', $invoiceIds)->exists()) {
                throw new DeliveryNotCancellableException('Goods were returned against this order\'s invoice, so it can no longer be cancelled.');
            }

            $movements = StockMovement::query()
                ->where('tenant_id', $order->tenant_id)
                ->where('reference_type', 'sales_order')
                ->where('reference_id', $order->id)
                ->where('type', 'sale')
                ->get();

            $this->restoreStock($movements, $order, $reason, $actor);
            $this->reverseCostOfGoodsSold($movements, $order, $actor);

            foreach ($invoices->where('status', '!=', 'void') as $invoice) {
                $this->voidInvoice->void($invoice, $actor, cancelling: true);
            }

            $order->update(['status' => 'cancelled', 'stock_deducted' => false]);
            $locked->update([
                'status' => 'cancelled',
                'notes' => trim(($locked->notes ? $locked->notes."\n" : '').'Cancelled'.($reason ? ": {$reason}" : '')),
            ]);

            $this->auditLog->record([
                'module' => 'sales',
                'entity_type' => 'Delivery',
                'entity_id' => $locked->id,
                'operation' => 'UPDATE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $order->warehouse_id,
                'prev_values' => ['status' => $delivery->status],
                'new_values' => ['status' => 'cancelled', 'reason' => $reason],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Puts back, batch by batch, the cartons the order took.
     *
     * @param  \Illuminate\Support\Collection<int, StockMovement>  $movements
     * @param  array{actor_id?: ?string}  $actor
     */
    private function restoreStock($movements, SalesOrder $order, ?string $reason, array $actor): void
    {
        $touchedWarehouses = [];

        foreach ($movements->groupBy('batch_id') as $batchId => $rows) {
            $batch = InventoryBatch::query()->whereKey($batchId)->lockForUpdate()->first();

            if ($batch === null) {
                throw new DeliveryNotCancellableException('A batch this order took stock from no longer exists, so the stock cannot be put back automatically.');
            }

            StockMovementContext::run(
                ['type' => 'sale_cancel', 'reference_type' => 'sales_order', 'reference_id' => $order->id, 'user_id' => $actor['actor_id'] ?? null, 'notes' => $reason],
                fn () => $batch->increment('qty_cartons', -$rows->sum('qty_cartons')),
            );

            $touchedWarehouses[$batch->warehouse_id] = true;
        }

        foreach (array_keys($touchedWarehouses) as $warehouseId) {
            Warehouse::find($warehouseId)?->recalculateStockValue();
        }
    }

    /**
     * The mirror image of the cost entry posted when the stock left, at the unit cost the ledger recorded then.
     *
     * @param  \Illuminate\Support\Collection<int, StockMovement>  $movements
     * @param  array{actor_id?: ?string}  $actor
     */
    private function reverseCostOfGoodsSold($movements, SalesOrder $order, array $actor): void
    {
        $cost = round($movements->sum(fn (StockMovement $movement) => -$movement->qty_cartons * (float) $movement->unit_cost), 2);

        if ($cost <= 0) {
            return;
        }

        $this->journalPosting->post(
            $order->tenant_id,
            $order->id,
            "Cancelled delivery: reversal of cost of goods sold for sales order {$order->number}",
            [
                ['account_code' => '1300', 'debit' => $cost],
                ['account_code' => '5100', 'credit' => $cost],
            ],
            $actor['actor_id'] ?? null,
        );
    }
}

<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Purchasing\Exceptions\PurchaseOrderAlreadyReceivedException;
use Modules\Purchasing\Exceptions\PurchaseOrderNotPricedException;
use Modules\Purchasing\Models\PurchaseOrder;

/**
 * The admin step of the pending-pricing flow: sets the cost of every line,
 * recomputes subtotal / tax / total exactly as create does, and releases the
 * order (`pending_pricing` → `draft`) so it can be sent and received. An
 * unreceived order that is already priced can be re-priced the same way; a
 * received or cancelled one cannot — its stock, payable and journal entry
 * were posted from the old costs.
 */
class PricePurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderTotalsCalculator $totals,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array<int, array{line_id: string, cost_per_carton?: ?float, cost_per_unit?: ?float}>  $costs
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function price(PurchaseOrder $po, array $costs, array $actor = []): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $costs, $actor) {
            $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if ($locked->stock_added) {
                throw new PurchaseOrderAlreadyReceivedException;
            }

            if ($locked->status === 'cancelled') {
                throw new PurchaseOrderNotPricedException('A cancelled purchase order cannot be priced.');
            }

            $costsByLine = collect($costs)->keyBy('line_id');
            $orderLines = $locked->lines()->get();

            // Rebuild the lines in their original form and let the shared calculator price them.
            $raw = $orderLines->map(function ($line) use ($costsByLine) {
                $cost = $costsByLine[$line->id];

                // A line ordered in a unit can be priced per unit or, equally, per carton.
                return $line->unit_id === null || ($cost['cost_per_carton'] ?? null) !== null
                    ? ['product_id' => $line->product_id, 'qty_cartons' => $line->qty_cartons, 'cost_per_carton' => $cost['cost_per_carton'] ?? null]
                    : ['product_id' => $line->product_id, 'unit_id' => $line->unit_id, 'qty' => $line->unit_qty, 'cost_per_unit' => $cost['cost_per_unit']];
            })->values()->all();

            $totals = $this->totals->calculate($raw);
            $previous = ['status' => $locked->status, 'total' => (string) $locked->total];

            foreach ($orderLines->values() as $index => $line) {
                $priced = $totals['lines'][$index];
                $line->update([
                    'cost_per_carton' => $priced['cost_per_carton'],
                    'cost_per_unit' => $priced['cost_per_unit'] ?? null,
                    'total' => $priced['total'],
                ]);
            }

            $locked->update([
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'status' => $locked->status === 'pending_pricing' ? 'draft' : $locked->status,
            ]);

            $this->auditLog->record([
                'module' => 'purchasing',
                'entity_type' => 'PurchaseOrder',
                'entity_id' => $locked->id,
                'operation' => 'UPDATE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $locked->warehouse_id,
                'prev_values' => $previous,
                'new_values' => ['status' => $locked->status, 'total' => (string) $locked->total, 'priced_lines' => (string) $orderLines->count()],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $locked->fresh(['lines']);
        });
    }
}

<?php

namespace Modules\Purchasing\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Purchasing\Exceptions\PurchaseOrderAlreadyReceivedException;
use Modules\Purchasing\Exceptions\PurchaseOrderNotPricedException;
use Modules\Purchasing\Models\PurchaseOrder;

/**
 * Edits a purchase order that hasn't been received. Header fields update in
 * place; `lines`, when given, replace every existing line and totals are
 * re-priced the same way as on create. A received PO is refused — its
 * stock, AP balance and journal entry were already posted from the old
 * values.
 *
 * The row is locked and stock_added re-checked inside the transaction, so a
 * concurrent receive can't post stock for lines this edit is replacing.
 */
class UpdatePurchaseOrderService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly PurchaseOrderTotalsCalculator $totals,
    ) {}

    /**
     * @param  array{
     *     supplier_id?: string, warehouse_id?: string, order_date?: string, expected_date?: ?string,
     *     notes?: ?string, status?: string,
     *     lines?: array<int, array{product_id: string, qty_cartons: int, cost_per_carton: float}>,
     * }  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function update(PurchaseOrder $po, array $data, array $actor = []): PurchaseOrder
    {
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        $totals = $lines === null ? null : $this->totals->calculate($lines);

        return DB::transaction(function () use ($po, $data, $totals, $actor) {
            $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if ($locked->stock_added) {
                throw new PurchaseOrderAlreadyReceivedException;
            }

            if ($totals !== null) {
                $data += ['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax_amount'], 'total' => $totals['total']];

                // Replacing the lines re-opens pricing unless every new line carries a cost
                // (only an administrator can supply one), and a priced replacement releases it.
                if (! $totals['priced']) {
                    $data['status'] = $locked->status === 'cancelled' ? 'cancelled' : 'pending_pricing';
                } elseif ($locked->status === 'pending_pricing') {
                    $data['status'] = $data['status'] ?? 'draft';
                }
            }

            // Pricing cannot be skipped by flipping the status.
            if ($locked->status === 'pending_pricing' && isset($data['status']) && ! in_array($data['status'], ['pending_pricing', 'cancelled'], true) && ($totals === null || ! $totals['priced'])) {
                throw new PurchaseOrderNotPricedException;
            }

            $prevValues = $this->auditValues($locked, array_keys($data));

            $locked->update($data);

            if ($totals !== null) {
                $locked->lines()->delete();

                foreach ($totals['lines'] as $line) {
                    $locked->lines()->create([
                        'product_id' => $line['product_id'],
                        'unit_id' => $line['unit_id'] ?? null,
                        'unit_qty' => $line['unit_qty'] ?? null,
                        'cost_per_unit' => $line['cost_per_unit'] ?? null,
                        'qty_cartons' => $line['qty_cartons'],
                        'cost_per_carton' => $line['cost_per_carton'],
                        'total' => $line['total'],
                    ]);
                }
            }

            $this->auditLog->record([
                'module' => 'purchasing',
                'entity_type' => 'PurchaseOrder',
                'entity_id' => $locked->id,
                'operation' => 'UPDATE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $locked->warehouse_id,
                'prev_values' => $prevValues,
                'new_values' => $this->auditValues($locked, array_keys($data)),
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $locked->fresh(['lines']);
        });
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, ?string>
     */
    private function auditValues(PurchaseOrder $po, array $keys): array
    {
        return array_map(
            fn ($value) => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : ($value === null ? null : (string) $value),
            $po->only($keys),
        );
    }
}

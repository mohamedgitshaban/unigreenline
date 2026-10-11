<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\CRM\Models\Customer;
use Modules\Sales\Exceptions\SalesOrderLockedException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\CartonCalculator;

/**
 * Edits a draft sales order. Header fields update in place; `lines`, when
 * given, replace every existing line and the order is re-priced and
 * re-validated (credit limit, FEFO availability) exactly as on create. Once
 * an order has moved past draft its stock, invoice and journal entry were
 * posted from the old values, so it is refused.
 */
class UpdateSalesOrderService
{
    public function __construct(
        private readonly CreateSalesOrderService $pricing,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated request data, `lines` optional.
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function update(SalesOrder $order, array $data, array $actor = []): SalesOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new SalesOrderLockedException;
            }

            $lines = $data['lines'] ?? null;
            unset($data['lines']);

            $fields = $data + $locked->only(['customer_id', 'warehouse_id', 'pay_type', 'invoice_discount']);
            $discount = (float) $fields['invoice_discount'];

            if ($lines !== null) {
                $priced = $this->pricing->price($lines, $discount);
                $computedLines = $priced['lines'];
            } else {
                $computedLines = $this->existingLinesAsPriced($locked);
                $priced = [
                    'subtotal' => (float) $locked->subtotal,
                    'tax' => (float) $locked->tax_amount,
                    'total' => round((float) $locked->subtotal + (float) $locked->tax_amount - $discount, 2),
                ];
            }

            $this->pricing->assertCanFulfil(
                Customer::findOrFail($fields['customer_id']),
                $fields['pay_type'],
                $priced['total'],
                $computedLines,
                $fields['warehouse_id'],
            );

            $changes = $data + ['subtotal' => $priced['subtotal'], 'tax_amount' => $priced['tax'], 'total' => $priced['total']];

            if (array_key_exists('grace_period', $data)) {
                $changes['due_date'] = now()->addDays($data['grace_period'])->toDateString();
            }

            $locked->fill($changes);
            $dirty = $locked->getDirty();
            $previous = array_intersect_key($locked->getOriginal(), $dirty);
            $locked->save();

            if ($lines !== null) {
                $locked->lines()->delete();

                foreach ($computedLines as $line) {
                    $locked->lines()->create([
                        'product_id' => $line['product_id'],
                        'warehouse_id' => $line['warehouse_id'],
                        'batch_no' => $line['batch_no'],
                        'qty' => $line['qty'],
                        'unit' => $line['unit'],
                        'unit_id' => $line['unit_id'],
                        'base_qty' => $line['base_qty'],
                        'unit_price' => $line['unit_price'],
                        'discount_pct' => $line['discount_pct'],
                        'free_qty' => $line['free_qty'],
                        'is_gift' => $line['is_gift'],
                        'subtotal' => $line['subtotal'],
                    ]);
                }
            }

            $this->auditLog->record([
                'module' => 'sales',
                'entity_type' => 'SalesOrder',
                'entity_id' => $locked->id,
                'operation' => 'UPDATE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $locked->warehouse_id,
                'prev_values' => array_map('strval', $previous),
                'new_values' => array_map('strval', $dirty) + ($lines !== null ? ['lines' => count($lines).' replaced'] : []),
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $locked->fresh(['lines']);
        });
    }

    /**
     * Existing lines in the shape the availability check wants, for edits
     * that only touch the header (e.g. switching warehouse).
     *
     * @return array<int, array{product_id: string, cartons_needed: int}>
     */
    private function existingLinesAsPriced(SalesOrder $order): array
    {
        return $order->lines()->with('product')->get()->map(fn ($line) => [
            'product_id' => $line->product_id,
            'warehouse_id' => $line->warehouse_id,
            'gift' => (bool) $line->is_gift,
            'cartons_needed' => CartonCalculator::cartonsNeeded(
                $line->qty, $line->free_qty, $line->unit, $line->product->carton_qty, $line->base_qty
            ),
        ])->all();
    }
}

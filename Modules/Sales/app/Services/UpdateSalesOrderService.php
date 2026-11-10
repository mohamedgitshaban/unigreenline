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

            // A warehouse sent as null means 'no change', not 'no warehouse'.
            if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] === null) {
                unset($data['warehouse_id']);
            }

            $fields = $data + $locked->only(['customer_id', 'warehouse_id', 'pay_type', 'invoice_discount', 'invoice_discount_pct', 'shipping_cost']);
            $discountPct = isset($fields['invoice_discount_pct']) ? (float) $fields['invoice_discount_pct'] : null;
            $discount = (float) $fields['invoice_discount'];
            // A flat discount sent now replaces a stored percentage, and the other way round.
            if (array_key_exists('invoice_discount', $data) && ! array_key_exists('invoice_discount_pct', $data)) {
                $discountPct = null;
            } elseif (array_key_exists('invoice_discount_pct', $data) && ! array_key_exists('invoice_discount', $data)) {
                $discount = 0.0;
            }
            $shipping = $locked->shipping_charged ? round((float) $fields['shipping_cost'], 2) : 0.0;

            if ($lines !== null) {
                $priced = $this->pricing->price($lines, $discount, $discountPct, $shipping);
                $computedLines = $this->pricing->withWarehouses($priced['lines'], $locked->tenant_id, $fields['warehouse_id']);
            } else {
                $computedLines = $this->existingLinesAsPriced($locked);
                $subtotal = (float) $locked->subtotal;
                $priced = [
                    'subtotal' => $subtotal,
                    'tax' => (float) $locked->tax_amount,
                    'discount' => $discountPct !== null ? round($subtotal * $discountPct / 100, 2) : $discount,
                ];
                $priced['total'] = round($subtotal + $priced['tax'] - $priced['discount'] + $shipping, 2);
            }

            $this->pricing->assertCanFulfil(
                Customer::findOrFail($fields['customer_id']),
                $fields['pay_type'],
                $priced['total'],
                $computedLines,
                $computedLines[0]['warehouse_id'] ?? $fields['warehouse_id'],
                $locked->sales_rep_id,
            );

            $changes = array_merge($data, [
                'subtotal' => $priced['subtotal'], 'tax_amount' => $priced['tax'], 'total' => $priced['total'],
                'invoice_discount' => $priced['discount'], 'invoice_discount_pct' => $discountPct,
            ]);

            if ($fields['pay_type'] === 'cash') {
                // A cash order is due at once, so a grace period has no meaning on it.
                $changes['grace_period'] = 0;
                $changes['due_date'] = null;
            } elseif (array_key_exists('grace_period', $data)) {
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
     * @return array<int, array{product_id: string, warehouse_id: ?string, regular_cartons: int, gift_cartons: int}>
     */
    private function existingLinesAsPriced(SalesOrder $order): array
    {
        return $order->lines()->with('product')->get()->map(function ($line) {
            $needs = CartonCalculator::stockNeeds(
                (bool) $line->is_gift, $line->qty, $line->free_qty, $line->unit, $line->product->carton_qty, $line->base_qty
            );

            return [
                'product_id' => $line->product_id,
                'warehouse_id' => $line->warehouse_id,
                'regular_cartons' => $needs['regular'],
                'gift_cartons' => $needs['gift'],
            ];
        })->all();
    }
}

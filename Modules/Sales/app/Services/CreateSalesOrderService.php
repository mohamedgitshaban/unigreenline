<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Notification;
use Modules\Core\Services\AuditLogService;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\FefoStockService;
use Modules\Inventory\Services\UnitConverter;
use Modules\Sales\Exceptions\CreditLimitExceededException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\CartonCalculator;

/**
 * Creates a sales order after validating (spec §5.6, §6):
 *  - stock is available across every line via FEFO (no deduction yet —
 *    that happens on the invoiced/delivered status transition, §5.1)
 *  - a credit order would not push the customer over their credit_limit
 *
 * Neither check writes anything if it fails — the whole thing is one
 * atomic operation.
 */
class CreateSalesOrderService
{
    private const CREDIT_WARNING_THRESHOLD = 0.85;

    public function __construct(
        private readonly FefoStockService $fefoStock,
        private readonly AuditLogService $auditLog,
        private readonly UnitConverter $units,
    ) {}

    /**
     * @param  array{
     *     tenant_id: string, customer_id: string, sales_rep_id: string, warehouse_id: string,
     *     pay_type: string, grace_period?: int, invoice_discount?: float, notes?: ?string, order_date?: string,
     *     actor_id?: ?string, actor_name?: ?string, ip_address?: ?string,
     *     lines: array<int, array{product_id: string, batch_no?: ?string, qty: int, unit: string, unit_price: float, discount_pct?: float, free_qty?: int}>,
     * }  $data
     */
    public function create(array $data): SalesOrder
    {
        $customer = Customer::findOrFail($data['customer_id']);
        ['lines' => $computedLines, 'subtotal' => $subtotal, 'tax' => $taxAmount, 'discount' => $invoiceDiscount, 'total' => $total] = $this->price($data['lines'], $data['invoice_discount'] ?? 0);

        $this->assertCanFulfil($customer, $data['pay_type'], $total, $computedLines, $data['warehouse_id']);

        return DB::transaction(function () use ($data, $customer, $computedLines, $subtotal, $taxAmount, $invoiceDiscount, $total) {
            $order = SalesOrder::create([
                'tenant_id' => $data['tenant_id'],
                'customer_id' => $customer->id,
                'sales_rep_id' => $data['sales_rep_id'],
                'warehouse_id' => $data['warehouse_id'],
                'status' => 'draft',
                'pay_type' => $data['pay_type'],
                'grace_period' => $data['grace_period'] ?? 0,
                'due_date' => isset($data['grace_period'])
                    ? now()->addDays($data['grace_period'])->toDateString()
                    : null,
                'invoice_discount' => $invoiceDiscount,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'stock_deducted' => false,
                'notes' => $data['notes'] ?? null,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
            ]);

            foreach ($computedLines as $line) {
                $order->lines()->create([
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

            if ($data['pay_type'] === 'credit' && $customer->utilizationAfter($total) >= self::CREDIT_WARNING_THRESHOLD) {
                Notification::create([
                    'tenant_id' => $data['tenant_id'],
                    'user_id' => $data['sales_rep_id'],
                    'type' => 'credit_warning',
                    'title' => 'Customer approaching credit limit',
                    'body' => "{$customer->name} would reach ".round($customer->utilizationAfter($total) * 100).'% of their credit limit with this order.',
                    'link_module' => 'crm',
                    'link_id' => $customer->id,
                    'unread' => true,
                ]);
            }

            $this->auditLog->record([
                'module' => 'sales',
                'entity_type' => 'SalesOrder',
                'entity_id' => $order->id,
                'operation' => 'INSERT',
                'user_id' => $data['actor_id'] ?? null,
                'user_name' => $data['actor_name'] ?? null,
                'tenant_id' => $data['tenant_id'],
                'warehouse_id' => $order->warehouse_id,
                'new_values' => [
                    'customer_id' => $order->customer_id,
                    'total' => (string) $order->total,
                    'pay_type' => $order->pay_type,
                ],
                'ip_address' => $data['ip_address'] ?? null,
            ]);

            return $order->load('lines');
        });
    }

    /**
     * Prices every line and totals the order.
     *
     * @param  array<int, array{product_id: string, batch_no?: ?string, qty: int, unit: string, unit_price: float, discount_pct?: float, free_qty?: int}>  $lines
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, tax: float, discount: float, total: float}
     */
    public function price(array $lines, float $invoiceDiscount = 0): array
    {
        $products = Product::query()->with('units')->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');

        $computedLines = array_map(
            fn (array $line, int $index) => $this->computeLine($line, $products[$line['product_id']], $index),
            $lines,
            array_keys($lines),
        );

        $subtotal = round(array_sum(array_column($computedLines, 'subtotal')), 2);
        $tax = round(array_sum(array_column($computedLines, 'tax')), 2);

        return [
            'lines' => $computedLines,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $invoiceDiscount,
            'total' => round($subtotal + $tax - $invoiceDiscount, 2),
        ];
    }

    /**
     * Credit-limit and FEFO stock-availability checks shared by create and
     * update; neither writes anything.
     *
     * @param  array<int, array<string, mixed>>  $computedLines
     */
    public function assertCanFulfil(Customer $customer, string $payType, float $total, array $computedLines, string $warehouseId): void
    {
        if ($payType === 'credit') {
            $this->enforceCreditLimit($customer, $total);
        }

        $shortfalls = $this->fefoStock->checkAvailability(array_map(
            fn (array $line) => [
                'product_id' => $line['product_id'],
                'warehouse_id' => $line['warehouse_id'] ?? $warehouseId,
                'cartons_needed' => $line['cartons_needed'],
                'gift' => $line['gift'] ?? $line['is_gift'] ?? false,
            ],
            $computedLines
        ));

        if ($shortfalls !== []) {
            throw new InsufficientStockException($shortfalls);
        }
    }

    /**
     * Prices one line. A line is ordered either as a plain `Carton`/`Pack`
     * (`unit`) or in one of the product's packaging units (`unit_id`). Gift
     * lines are sold at 0; every other line's price, after its discount, must
     * not fall below the product's minimum selling price for that unit.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function computeLine(array $line, Product $product, int $index): array
    {
        $isGift = (bool) ($line['is_gift'] ?? false);
        $discountPct = $line['discount_pct'] ?? 0;
        $freeQty = $line['free_qty'] ?? 0;
        $unitId = $line['unit_id'] ?? null;
        $explicitBase = $this->units->baseQty($product, $unitId, "lines.{$index}.unit_id");

        if ($explicitBase !== null) {
            $unit = $explicitBase === $product->carton_qty ? 'Carton' : 'Pack';
            $baseQty = $explicitBase;
        } else {
            $unit = $line['unit'];
            $baseQty = $unit === 'Carton' ? $product->carton_qty : 1;
        }

        $unitPrice = $isGift ? 0 : (float) $line['unit_price'];

        if (! $isGift && $product->min_selling_price !== null) {
            $floor = round((float) $product->min_selling_price * $baseQty, 2);

            if (round($unitPrice * (1 - $discountPct / 100), 2) < $floor) {
                throw ValidationException::withMessages([
                    "lines.{$index}.unit_price" => "The price after discount cannot be below the minimum selling price of {$floor} for this unit.",
                ]);
            }
        }

        $subtotal = round($line['qty'] * $unitPrice * (1 - $discountPct / 100), 2);
        $tax = round($subtotal * (float) $product->tax_pct / 100, 2);

        $cartonsNeeded = CartonCalculator::cartonsNeeded(
            $line['qty'], $freeQty, $unit, $product->carton_qty, $explicitBase
        );

        return [
            'product_id' => $line['product_id'],
            'warehouse_id' => $line['warehouse_id'] ?? null,
            'batch_no' => $line['batch_no'] ?? null,
            'qty' => $line['qty'],
            'unit' => $unit,
            'unit_id' => $unitId,
            'base_qty' => $explicitBase,
            'unit_price' => $unitPrice,
            'discount_pct' => $discountPct,
            'free_qty' => $freeQty,
            'is_gift' => $isGift,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'cartons_needed' => $cartonsNeeded,
        ];
    }

    private function enforceCreditLimit(Customer $customer, float $orderTotal): void
    {
        if ($customer->utilizationAfter($orderTotal) > 1.0) {
            throw new CreditLimitExceededException(
                $customer->id,
                (float) $customer->credit_limit,
                (float) $customer->balance,
                $orderTotal,
            );
        }
    }
}

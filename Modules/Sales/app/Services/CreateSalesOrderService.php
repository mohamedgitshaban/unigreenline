<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Notification;
use Modules\Core\Services\AuditLogService;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\FefoStockService;
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
        $products = Product::query()->whereIn('id', array_column($data['lines'], 'product_id'))->get()->keyBy('id');

        $computedLines = array_map(
            fn (array $line) => $this->computeLine($line, $products[$line['product_id']]),
            $data['lines']
        );

        $subtotal = round(array_sum(array_column($computedLines, 'subtotal')), 2);
        $taxAmount = round(array_sum(array_column($computedLines, 'tax')), 2);
        $invoiceDiscount = $data['invoice_discount'] ?? 0;
        $total = round($subtotal + $taxAmount - $invoiceDiscount, 2);

        if ($data['pay_type'] === 'credit') {
            $this->enforceCreditLimit($customer, $total);
        }

        $fefoLines = array_map(
            fn (array $line) => [
                'product_id' => $line['product_id'],
                'warehouse_id' => $data['warehouse_id'],
                'cartons_needed' => $line['cartons_needed'],
            ],
            $computedLines
        );

        $shortfalls = $this->fefoStock->checkAvailability($fefoLines);

        if ($shortfalls !== []) {
            throw new InsufficientStockException($shortfalls);
        }

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
                    'batch_no' => $line['batch_no'],
                    'qty' => $line['qty'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'discount_pct' => $line['discount_pct'],
                    'free_qty' => $line['free_qty'],
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
     * @return array{product_id: string, batch_no: ?string, qty: int, unit: string, unit_price: float, discount_pct: float, free_qty: int, subtotal: float, tax: float, cartons_needed: int}
     */
    private function computeLine(array $line, Product $product): array
    {
        $discountPct = $line['discount_pct'] ?? 0;
        $freeQty = $line['free_qty'] ?? 0;
        $subtotal = round($line['qty'] * $line['unit_price'] * (1 - $discountPct / 100), 2);
        $tax = round($subtotal * (float) $product->tax_pct / 100, 2);

        $cartonsNeeded = CartonCalculator::cartonsNeeded(
            $line['qty'], $freeQty, $line['unit'], $product->carton_qty
        );

        return [
            'product_id' => $line['product_id'],
            'batch_no' => $line['batch_no'] ?? null,
            'qty' => $line['qty'],
            'unit' => $line['unit'],
            'unit_price' => $line['unit_price'],
            'discount_pct' => $discountPct,
            'free_qty' => $freeQty,
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

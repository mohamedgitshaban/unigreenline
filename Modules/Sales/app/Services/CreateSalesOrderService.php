<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Notification;
use Modules\Core\Models\TenantSetting;
use Modules\Core\Services\AuditLogService;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\FefoStockService;
use Modules\Inventory\Services\UnitConverter;
use Modules\Sales\Exceptions\CreditLimitExceededException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\CartonCalculator;
use Modules\Sales\Support\StockLines;

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
     *     tenant_id: string, customer_id: string, sales_rep_id: string,
     *     pay_type: string, grace_period?: int, invoice_discount?: float, invoice_discount_pct?: float, shipping_cost?: float,
     *     warehouse_id?: ?string, notes?: ?string, order_date?: string, delivery_date?: ?string,
     *     actor_id?: ?string, actor_name?: ?string, ip_address?: ?string,
     *     lines: array<int, array{product_id: string, warehouse_id?: ?string, batch_no?: ?string, qty: int, unit: string, unit_price: float, discount_pct?: float, free_qty?: int}>,
     * }  $data
     */
    public function create(array $data): SalesOrder
    {
        $customer = Customer::findOrFail($data['customer_id']);
        $isCredit = $data['pay_type'] === 'credit';
        $shippingCharged = $this->shippingIsCharged($data['tenant_id']);
        $shippingCost = round((float) ($data['shipping_cost'] ?? 0), 2);

        [
            'lines' => $computedLines, 'subtotal' => $subtotal, 'tax' => $taxAmount, 'discount' => $invoiceDiscount, 'total' => $total,
        ] = $this->price(
            $data['lines'],
            (float) ($data['invoice_discount'] ?? 0),
            isset($data['invoice_discount_pct']) ? (float) $data['invoice_discount_pct'] : null,
            $shippingCharged ? $shippingCost : 0.0,
        );

        $computedLines = $this->withWarehouses($computedLines, $data['tenant_id'], $data['warehouse_id'] ?? null);

        // The order's own warehouse is the first line's; every line carries its own.
        $warehouseId = $computedLines[0]['warehouse_id'];

        $this->assertCanFulfil($customer, $data['pay_type'], $total, $computedLines, $warehouseId, $data['sales_rep_id'] ?? null);

        return DB::transaction(function () use ($data, $customer, $computedLines, $warehouseId, $subtotal, $taxAmount, $invoiceDiscount, $total, $isCredit, $shippingCost, $shippingCharged) {
            $order = SalesOrder::create([
                'tenant_id' => $data['tenant_id'],
                'customer_id' => $customer->id,
                'sales_rep_id' => $data['sales_rep_id'],
                'warehouse_id' => $warehouseId,
                'status' => 'draft',
                'pay_type' => $data['pay_type'],
                // A grace period only means something on credit; a cash order is due at once.
                'grace_period' => $isCredit ? ($data['grace_period'] ?? 0) : 0,
                'due_date' => $isCredit && isset($data['grace_period'])
                    ? now()->addDays($data['grace_period'])->toDateString()
                    : null,
                'invoice_discount' => $invoiceDiscount,
                'invoice_discount_pct' => $data['invoice_discount_pct'] ?? null,
                'shipping_cost' => $shippingCost,
                'shipping_charged' => $shippingCharged,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'stock_deducted' => false,
                'notes' => $data['notes'] ?? null,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'delivery_date' => $data['delivery_date'] ?? null,
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
     * Prices every line and totals the order. The invoice discount is either a flat amount or, when
     * `$discountPct` is given, that percentage of the subtotal. Shipping is added only when the company
     * charges it to the customer (the caller passes 0 otherwise).
     *
     * @param  array<int, array{product_id: string, batch_no?: ?string, qty: int, unit: string, unit_price: float, discount_pct?: float, free_qty?: int}>  $lines
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, tax: float, discount: float, shipping: float, total: float}
     */
    public function price(array $lines, float $invoiceDiscount = 0, ?float $discountPct = null, float $shipping = 0): array
    {
        $products = Product::query()->with('units')->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');

        $computedLines = array_map(
            fn (array $line, int $index) => $this->computeLine($line, $products[$line['product_id']], $index),
            $lines,
            array_keys($lines),
        );

        $subtotal = round(array_sum(array_column($computedLines, 'subtotal')), 2);
        $tax = round(array_sum(array_column($computedLines, 'tax')), 2);
        $discount = $discountPct !== null ? round($subtotal * $discountPct / 100, 2) : $invoiceDiscount;

        return [
            'lines' => $computedLines,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $discount,
            'shipping' => $shipping,
            'total' => round($subtotal + $tax - $discount + $shipping, 2),
        ];
    }

    /**
     * Whether shipping is added to what the customer pays: the company setting says it is the company's own
     * cost (the default) or a charge to the customer.
     */
    public function shippingIsCharged(string $tenantId): bool
    {
        return ! TenantSetting::forTenant($tenantId)->shipping_cost_paid_by_company;
    }

    /**
     * Fills in the warehouse of any line that did not name one: the order's own warehouse when given,
     * otherwise whichever active warehouse holds the stock.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public function withWarehouses(array $lines, string $tenantId, ?string $defaultWarehouseId): array
    {
        foreach ($lines as $index => $line) {
            if ($line['warehouse_id'] !== null) {
                continue;
            }

            $warehouseId = $defaultWarehouseId ?? $this->fefoStock->suggestWarehouse(
                $tenantId,
                $line['product_id'],
                $line['is_gift'] ? $line['gift_cartons'] : $line['regular_cartons'],
                $line['is_gift'],
            );

            if ($warehouseId === null) {
                throw ValidationException::withMessages(["lines.{$index}.warehouse_id" => 'No active warehouse is available to take this line from.']);
            }

            $lines[$index]['warehouse_id'] = $warehouseId;
        }

        return $lines;
    }

    /**
     * Credit-limit and FEFO stock-availability checks shared by create and
     * update; neither writes anything except the alert below. When gift stock is
     * what ran short, the warehouse manager and the order's owner are told.
     *
     * @param  array<int, array<string, mixed>>  $computedLines
     */
    public function assertCanFulfil(Customer $customer, string $payType, float $total, array $computedLines, string $warehouseId, ?string $ownerId = null): void
    {
        if ($payType === 'credit') {
            $this->enforceCreditLimit($customer, $total);
        }

        $shortfalls = $this->fefoStock->checkAvailability(StockLines::fromComputed($computedLines, $warehouseId));

        if ($shortfalls !== []) {
            $this->alertGiftShortage($customer->tenant_id, $shortfalls, $ownerId);

            throw new InsufficientStockException($shortfalls);
        }
    }

    /**
     * @param  array<int, array{product_id: string, warehouse_id: string, gift?: bool, needed: int, available: int}>  $shortfalls
     */
    private function alertGiftShortage(string $tenantId, array $shortfalls, ?string $ownerId): void
    {
        foreach (array_filter($shortfalls, fn (array $shortfall) => $shortfall['gift'] ?? false) as $shortfall) {
            $product = Product::query()->find($shortfall['product_id']);
            $warehouse = Warehouse::query()->find($shortfall['warehouse_id']);

            foreach (array_unique(array_filter([$warehouse?->manager_id, $ownerId])) as $userId) {
                $alreadyTold = Notification::query()->where('user_id', $userId)->where('type', 'gift_stock_short')
                    ->where('link_id', $shortfall['product_id'])->where('unread', true)->exists();

                if ($alreadyTold) {
                    continue;
                }

                Notification::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'type' => 'gift_stock_short',
                    'title' => 'Gift stock ran out',
                    'body' => "An order needed {$shortfall['needed']} gift carton(s) of ".($product?->name ?? 'a product').' from '.($warehouse?->name ?? 'a warehouse')." but only {$shortfall['available']} are left.",
                    'link_module' => 'inventory',
                    'link_entity' => 'products',
                    'link_id' => $shortfall['product_id'],
                    'unread' => true,
                ]);
            }
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

        $needs = CartonCalculator::stockNeeds($isGift, $line['qty'], $freeQty, $unit, $product->carton_qty, $explicitBase);

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
            'regular_cartons' => $needs['regular'],
            'gift_cartons' => $needs['gift'],
            'cartons_needed' => $needs['regular'] + $needs['gift'],
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

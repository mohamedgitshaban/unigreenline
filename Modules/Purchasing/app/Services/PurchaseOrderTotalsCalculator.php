<?php

namespace Modules\Purchasing\Services;

use Modules\Inventory\Models\Product;

/**
 * Line totals, tax and PO totals — shared by create and update so an edited
 * PO is priced exactly like a new one. Tax comes from each product's tax_pct.
 */
class PurchaseOrderTotalsCalculator
{
    /**
     * @param  array<int, array{product_id: string, qty_cartons: int, cost_per_carton: float}>  $lines
     * @return array{lines: array<int, array{product_id: string, qty_cartons: int, cost_per_carton: float, total: float, tax: float}>, subtotal: float, tax_amount: float, total: float}
     */
    public function calculate(array $lines): array
    {
        $products = Product::query()->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');

        $computedLines = array_map(function (array $line) use ($products) {
            $total = round($line['qty_cartons'] * $line['cost_per_carton'], 2);
            $tax = round($total * (float) $products[$line['product_id']]->tax_pct / 100, 2);

            return [...$line, 'total' => $total, 'tax' => $tax];
        }, $lines);

        $subtotal = round(array_sum(array_column($computedLines, 'total')), 2);
        $taxAmount = round(array_sum(array_column($computedLines, 'tax')), 2);

        return [
            'lines' => $computedLines,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => round($subtotal + $taxAmount, 2),
        ];
    }
}

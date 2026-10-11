<?php

namespace Modules\Purchasing\Services;

use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\UnitConverter;

/**
 * Line totals, tax and PO totals — shared by create and update so an edited
 * PO is priced exactly like a new one. Tax comes from each product's tax_pct.
 */
class PurchaseOrderTotalsCalculator
{
    public function __construct(private readonly UnitConverter $units) {}

    /**
     * A line is either in cartons (`qty_cartons`, `cost_per_carton`) or in one of
     * the product's packaging units (`unit_id`, `qty`, `cost_per_unit`), in which
     * case it's converted to whole cartons — the line total stays qty × cost_per_unit.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{lines: array<int, array{product_id: string, qty_cartons: int, cost_per_carton: float, total: float, tax: float}>, subtotal: float, tax_amount: float, total: float}
     */
    public function calculate(array $lines): array
    {
        $products = Product::query()->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');

        $computedLines = array_map(function (array $line, int $index) use ($products) {
            $line = $this->normalize($line, $products[$line['product_id']], $index);
            $total = $line['total'] ?? round($line['qty_cartons'] * $line['cost_per_carton'], 2);
            $tax = round($total * (float) $products[$line['product_id']]->tax_pct / 100, 2);

            return [...$line, 'total' => $total, 'tax' => $tax];
        }, $lines, array_keys($lines));

        $subtotal = round(array_sum(array_column($computedLines, 'total')), 2);
        $taxAmount = round(array_sum(array_column($computedLines, 'tax')), 2);

        return [
            'lines' => $computedLines,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => round($subtotal + $taxAmount, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalize(array $line, Product $product, int $index): array
    {
        if (empty($line['unit_id'])) {
            return $line;
        }

        $cartons = $this->units->toCartons($product, (int) $line['qty'], $line['unit_id'], "lines.{$index}.qty");
        $total = round($line['qty'] * $line['cost_per_unit'], 2);

        return [
            'product_id' => $line['product_id'],
            'unit_id' => $line['unit_id'],
            'unit_qty' => (int) $line['qty'],
            'cost_per_unit' => $line['cost_per_unit'],
            'qty_cartons' => $cartons,
            'cost_per_carton' => round($total / $cartons, 2),
            'total' => $total,
        ];
    }
}

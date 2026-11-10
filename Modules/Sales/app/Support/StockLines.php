<?php

namespace Modules\Sales\Support;

/**
 * Turns sales order lines into the flat lines the FEFO stock service checks and deducts. An ordinary line asks
 * regular stock for what is paid for and gift stock for its free quantity; a gift line asks gift stock only
 * (see CartonCalculator::stockNeeds). Order creation, editing and the stock deduction all build their lines here,
 * so the availability check and the real deduction can never disagree.
 */
class StockLines
{
    /**
     * @param  iterable<int, object>  $lines  Eloquent order lines with their product loaded.
     * @return array<int, array{product_id: string, warehouse_id: string, cartons_needed: int, gift: bool}>
     */
    public static function fromOrderLines(iterable $lines, string $orderWarehouseId): array
    {
        $out = [];

        foreach ($lines as $line) {
            $needs = CartonCalculator::stockNeeds(
                (bool) $line->is_gift, $line->qty, $line->free_qty, $line->unit, $line->product->carton_qty, $line->base_qty
            );

            array_push($out, ...self::entries($line->product_id, $line->warehouse_id ?? $orderWarehouseId, $needs));
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $computedLines  Lines as priced by CreateSalesOrderService.
     * @return array<int, array{product_id: string, warehouse_id: string, cartons_needed: int, gift: bool}>
     */
    public static function fromComputed(array $computedLines, string $orderWarehouseId): array
    {
        $out = [];

        foreach ($computedLines as $line) {
            array_push($out, ...self::entries(
                $line['product_id'],
                $line['warehouse_id'] ?? $orderWarehouseId,
                ['regular' => $line['regular_cartons'], 'gift' => $line['gift_cartons']],
            ));
        }

        return $out;
    }

    /**
     * @param  array{regular: int, gift: int}  $needs
     * @return array<int, array{product_id: string, warehouse_id: string, cartons_needed: int, gift: bool}>
     */
    private static function entries(string $productId, string $warehouseId, array $needs): array
    {
        $entries = [];

        foreach (['regular' => false, 'gift' => true] as $kind => $gift) {
            if ($needs[$kind] > 0) {
                $entries[] = ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'cartons_needed' => $needs[$kind], 'gift' => $gift];
            }
        }

        return $entries;
    }
}

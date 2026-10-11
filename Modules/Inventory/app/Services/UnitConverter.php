<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Exceptions\UnitConversionException;
use Modules\Inventory\Models\Product;

/**
 * Converts between a product's packaging units. Every quantity is counted
 * in the product's smallest unit ("base units"); stock lives in the largest
 * unit (the carton, `carton_qty` base units). A product with no explicit
 * ladder is treated as Pack (1) → Carton (carton_qty), as before units existed.
 */
class UnitConverter
{
    /**
     * Base units in the chosen unit; null `$unitId` means "legacy": no unit chosen.
     */
    public function baseQty(Product $product, ?string $unitId, string $field = 'unit_id'): ?int
    {
        if ($unitId === null) {
            return null;
        }

        $level = $product->units->firstWhere('unit_id', $unitId);

        if ($level === null) {
            throw UnitConversionException::forField($field, "That unit is not one of {$product->name}'s packaging units.");
        }

        return $level->base_qty;
    }

    /**
     * Whole stock cartons for a quantity in the chosen unit.
     */
    public function toCartons(Product $product, int $qty, string $unitId, string $field = 'qty'): int
    {
        $base = $this->baseQty($product, $unitId, preg_replace('/qty$/', 'unit_id', $field));
        $baseUnits = $qty * $base;

        if ($baseUnits % $product->carton_qty !== 0) {
            throw UnitConversionException::forField(
                $field,
                "{$qty} of that unit is {$baseUnits} base units, which is not a whole number of cartons ({$product->carton_qty} per carton).",
            );
        }

        return intdiv($baseUnits, $product->carton_qty);
    }
}

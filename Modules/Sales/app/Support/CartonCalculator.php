<?php

namespace Modules\Sales\Support;

/**
 * Shared by order creation (stock validation) and the status-transition
 * deduction — both must agree on how many cartons a line needs, or
 * validation and the real deduction could disagree.
 */
class CartonCalculator
{
    /**
     * Pack-unit lines round up to whole cartons — batches only track
     * carton-level quantities, so a sale of a handful of packs still
     * debits a whole carton (spec has no pack-level batch tracking).
     */
    public static function cartonsNeeded(int $qty, int $freeQty, string $unit, int $productCartonQty): int
    {
        $unitsNeeded = $qty + $freeQty;

        return $unit === 'Carton'
            ? $unitsNeeded
            : (int) ceil($unitsNeeded / $productCartonQty);
    }
}

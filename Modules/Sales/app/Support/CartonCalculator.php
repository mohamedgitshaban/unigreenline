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
    public static function cartonsNeeded(int $qty, int $freeQty, string $unit, int $productCartonQty, ?int $baseQty = null): int
    {
        $unitsNeeded = $qty + $freeQty;

        // Ordered in one of the product's packaging units: count smallest
        // units and round up to whole cartons.
        if ($baseQty !== null) {
            return (int) ceil($unitsNeeded * $baseQty / $productCartonQty);
        }

        return $unit === 'Carton'
            ? $unitsNeeded
            : (int) ceil($unitsNeeded / $productCartonQty);
    }

    /**
     * What a line takes from each kind of stock. An ordinary line pays for `qty` out of regular stock and its
     * free quantity comes out of gift stock; a gift line takes everything from gift stock. Paid and free
     * quantities round up to whole cartons separately, since they come from different batches.
     *
     * @return array{regular: int, gift: int}
     */
    public static function stockNeeds(bool $isGift, int $qty, int $freeQty, string $unit, int $productCartonQty, ?int $baseQty = null): array
    {
        if ($isGift) {
            return ['regular' => 0, 'gift' => self::cartonsNeeded($qty, $freeQty, $unit, $productCartonQty, $baseQty)];
        }

        return [
            'regular' => self::cartonsNeeded($qty, 0, $unit, $productCartonQty, $baseQty),
            'gift' => $freeQty > 0 ? self::cartonsNeeded($freeQty, 0, $unit, $productCartonQty, $baseQty) : 0,
        ];
    }
}

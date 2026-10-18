<?php

namespace Modules\Manufacturing\Support;

use Modules\Manufacturing\Models\ProductPlanning;

/**
 * Turns a shortage into the quantities actually ordered, by the product's lot-sizing rule:
 *  - exact:    the shortage, rounded up to a whole carton;
 *  - fixed:    whole multiples of the fixed lot (at least one);
 *  - min_max:  at least the minimum; never more than the maximum in one order (a bigger
 *              need is split into several);
 *  - multiple: rounded up to a multiple of the given quantity.
 * Stock is held in whole cartons, so every result is a whole number.
 */
final class LotSizer
{
    /**
     * @return array<int, int> One entry per order to place (usually one).
     */
    public static function lots(float $shortage, ?ProductPlanning $planning): array
    {
        $needed = max(1, (int) ceil(round($shortage, 4) - 1e-9));
        $rule = $planning?->lot_rule ?? 'exact';

        $qty = match ($rule) {
            'fixed' => self::roundUpTo($needed, max(1, (int) $planning->lot_qty)),
            'multiple' => self::roundUpTo($needed, max(1, (int) $planning->multiple_qty)),
            'min_max' => max($needed, (int) $planning->min_qty),
            default => $needed,
        };

        if ($rule === 'min_max' && $planning->max_qty > 0 && $qty > $planning->max_qty) {
            $lots = array_fill(0, intdiv($qty, $planning->max_qty), (int) $planning->max_qty);

            if ($qty % $planning->max_qty > 0) {
                // The remainder honours the minimum too.
                $lots[] = max($qty % $planning->max_qty, (int) $planning->min_qty);
            }

            return $lots;
        }

        return [$qty];
    }

    private static function roundUpTo(int $qty, int $step): int
    {
        return (int) (ceil($qty / $step) * $step);
    }
}

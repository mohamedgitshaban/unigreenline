<?php

namespace Modules\Inventory\Support;

use Closure;
use Modules\Inventory\Observers\InventoryBatchObserver;

/**
 * Says why stock is about to change, for the ledger. A service that moves stock wraps the work:
 *
 *     StockMovementContext::run(['type' => 'sale', 'reference_type' => 'sales_order', 'reference_id' => $order->id], fn () => ...);
 *
 * and every batch quantity change made inside is written to the stock ledger with that type and reference
 * (see {@see InventoryBatchObserver}). Changes made with no context are
 * recorded as `adjustment`, so the ledger always adds up. Contexts nest; an inner one overrides the outer.
 */
final class StockMovementContext
{
    /** @var array<int, array<string, mixed>> */
    private static array $stack = [];

    /**
     * @template T
     *
     * @param  array{type?: string, reference_type?: ?string, reference_id?: ?string, counterpart_warehouse_id?: ?string, notes?: ?string}  $context
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(array $context, Closure $callback): mixed
    {
        self::$stack[] = $context;

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        return array_merge([], ...self::$stack);
    }
}

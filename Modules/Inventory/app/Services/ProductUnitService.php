<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;

/**
 * Writes a product's packaging ladder. The client sends it smallest unit
 * first, each larger unit saying how many of the previous one it holds —
 * "Packet; Package contains 10; Carton contains 3" — and base quantities are
 * derived by multiplying up (1, 10, 30). The largest unit is the stock unit,
 * so the product's carton_qty becomes its base quantity.
 */
class ProductUnitService
{
    /**
     * @param  array<int, array{unit_id: string, contains?: ?int}>  $levels
     */
    public function sync(Product $product, array $levels): void
    {
        DB::transaction(function () use ($product, $levels) {
            $product->units()->delete();

            $base = 1;

            foreach (array_values($levels) as $position => $level) {
                $base = $position === 0 ? 1 : $base * $level['contains'];

                $product->units()->create([
                    'unit_id' => $level['unit_id'],
                    'position' => $position,
                    'contains' => $position === 0 ? null : $level['contains'],
                    'base_qty' => $base,
                ]);
            }

            $product->update(['carton_qty' => $base]);
            $product->unsetRelation('units');
        });
    }

    /**
     * Base units in the largest unit — the stock unit's size.
     *
     * @param  array<int, array{unit_id: string, contains?: ?int}>  $levels
     */
    public function cartonQty(array $levels): int
    {
        return array_reduce(array_slice(array_values($levels), 1), fn (int $base, array $level) => $base * $level['contains'], 1);
    }

    /**
     * Name of the smallest unit in a ladder, for defaulting `pack_unit`.
     *
     * @param  array<int, array{unit_id: string}>  $levels
     */
    public function smallestUnitName(array $levels): ?string
    {
        return Unit::query()->whereKey($levels[0]['unit_id'])->value('name');
    }
}

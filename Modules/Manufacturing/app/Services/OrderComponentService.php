<?php

namespace Modules\Manufacturing\Services;

use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductionOrderComponent;

/**
 * The materials an order needs, copied from the recipe when the order is
 * confirmed so that later recipe edits cannot change an order under way.
 *
 * Need is worked out exactly (cartons, four decimals, scrap allowance included)
 * and issued in whole cartons, rounding up. Consumption follows output: after
 * `n` of `Q` planned cartons are made, `ceil(need × n / Q)` cartons of each
 * component should have been issued — rounding the running total, not each
 * step, so the whole-carton rounding never adds up to more than the full need.
 */
class OrderComponentService
{
    /**
     * @return \Illuminate\Support\Collection<int, ProductionOrderComponent>
     */
    public function snapshot(ProductionOrder $order, Bom $bom)
    {
        $order->components()->delete();

        // Runs of the recipe the planned output amounts to; fractional for a backorder or a planned quantity.
        $runs = $order->output_qty_cartons / max(1, $bom->output_qty_cartons);

        foreach ($bom->lines()->get() as $line) {
            $required = round($line->qty_cartons * $runs * (1 + (float) $line->scrap_pct / 100), 4);

            $order->components()->create([
                'product_id' => $line->component_product_id,
                'bom_line_id' => $line->id,
                'required_qty' => $required,
                'required_cartons' => max(1, $this->wholeCartons($required)),
                'scrap_pct' => $line->scrap_pct,
                'optional' => $line->optional,
            ]);
        }

        return $order->components()->get();
    }

    /**
     * Cartons of the component that should have been issued once `$output` of the order's planned cartons are made.
     */
    public function targetConsumed(ProductionOrderComponent $component, int $plannedOutput, int $output): int
    {
        if ($plannedOutput <= 0) {
            return 0;
        }

        $fraction = min(1.0, $output / $plannedOutput);

        return min($component->required_cartons, $this->wholeCartons(round((float) $component->required_qty * $fraction, 4)));
    }

    /**
     * Rounds a quantity up to whole cartons, ignoring float noise below a ten-thousandth.
     */
    public function wholeCartons(float $qty): int
    {
        return (int) ceil(round($qty, 4) - 1e-9);
    }
}

<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Product;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Models\ProductionOrder;

/**
 * The costing rules, in one place.
 *
 * Planned cost (the estimate, fixed at confirmation):
 *   material  = Σ exact component need (scrap allowance included) × standard cost of the component
 *   operation = Σ operation minutes ÷ 60 × (machine + labour rate of the work center)
 *   overhead  = the order's conversion cost + overhead rate × planned material
 * Actual cost builds up as work is recorded:
 *   material  = value of the batches issued (at their own cost) + material scrap carried by the product
 *   operation = labour and machine time of completed work orders, at the work center rates
 *   overhead  = conversion cost spread over the output made so far + overhead rate × actual material
 * Finished goods are valued from what is "in progress" — everything incurred, less
 * what earlier receipts already carried — by the share of the work this receipt represents.
 * Good output absorbs the cost of rejected units made with it.
 */
class ProductionCostingService
{
    public function standardCost(Product $product): float
    {
        return round((float) $product->pack_cost_price * $product->carton_qty, 2);
    }

    /**
     * @return array{planned_material_cost: float, planned_operation_cost: float, planned_overhead_cost: float}
     */
    public function estimate(ProductionOrder $order, Bom $bom, ManufacturingSetting $settings): array
    {
        $components = $order->components()->where('optional', false)->with('product')->get();
        $material = round($components->sum(fn ($c) => (float) $c->required_qty * $this->standardCost($c->product)), 2);

        $operation = 0.0;
        if ($bom->routing_id !== null) {
            foreach ($bom->routing()->first()->operations()->with('workCenter')->get() as $step) {
                $minutes = $step->plannedMinutes($order->output_qty_cartons, (float) $step->workCenter->efficiency_pct);
                $operation += $minutes / 60 * ((float) $step->workCenter->cost_per_hour + (float) $step->workCenter->labor_cost_per_hour);
            }
        }

        return [
            'planned_material_cost' => $material,
            'planned_operation_cost' => round($operation, 2),
            'planned_overhead_cost' => round((float) $order->conversion_cost + (float) $settings->overhead_rate_pct / 100 * $material, 2),
        ];
    }

    /**
     * What the order has actually incurred so far, straight from its records.
     *
     * @return array{material: float, operation: float, scrap: float}
     */
    public function incurred(ProductionOrder $order): array
    {
        $material = (float) DB::table('production_consumptions')
            ->where('production_order_id', $order->id)
            ->where(fn ($q) => $q->where('kind', 'consume')->orWhere(fn ($q2) => $q2->where('kind', 'scrap')->where('absorbed', true)))
            ->sum('line_value');

        $scrap = (float) DB::table('production_consumptions')->where('production_order_id', $order->id)->where('kind', 'scrap')->sum('line_value');

        $operation = (float) DB::table('work_orders')
            ->where('production_order_id', $order->id)->where('status', 'completed')
            ->selectRaw('COALESCE(SUM(labor_cost + machine_cost), 0) as total')->value('total');

        return ['material' => round($material, 2), 'operation' => round($operation, 2), 'scrap' => round($scrap, 2)];
    }

    /**
     * Overhead that should have been applied once `$cumulativeOutput` cartons (good and rejected) are made.
     */
    public function overheadTarget(ProductionOrder $order, ManufacturingSetting $settings, float $material, int $cumulativeOutput): float
    {
        $share = $order->output_qty_cartons > 0 ? min(1.0, $cumulativeOutput / $order->output_qty_cartons) : 0.0;

        return round((float) $order->conversion_cost * $share + (float) $settings->overhead_rate_pct / 100 * $material, 2);
    }

    /**
     * Values a receipt of `$good` good and `$rejected` rejected cartons, after the materials for it have been issued.
     *
     * @return array{
     *     value: float, material_portion: float, overhead_applied: float, overhead_total: float,
     *     share: float, pool: float, material: float, operation: float
     * }
     */
    public function valueReceipt(ProductionOrder $order, ManufacturingSetting $settings, int $good, int $rejected): array
    {
        $incurred = $this->incurred($order);
        $cumulative = $order->produced_qty_cartons + $order->rejected_qty_cartons + $good + $rejected;

        $overheadTotal = $this->overheadTarget($order, $settings, $incurred['material'], $cumulative);
        $overheadApplied = round(max(0.0, $overheadTotal - (float) $order->actual_overhead_cost), 2);

        $pool = round($incurred['material'] + $incurred['operation'] + $overheadTotal - (float) $order->capitalized_cost, 2);

        $share = 0.0;
        if ($good > 0) {
            if ($cumulative >= $order->output_qty_cartons) {
                $share = 1.0;
            } else {
                $basis = max($cumulative, (int) round($order->output_qty_cartons * $this->materialProgress($order)));
                $denominator = max(1, $basis - $order->produced_qty_cartons - $order->rejected_qty_cartons);
                $share = min(1.0, ($good + $rejected) / $denominator);
            }
        }

        $value = round(max(0.0, $pool) * $share, 2);
        $materialPool = max(0.0, $incurred['material'] - (float) $order->capitalized_material);
        $materialPortion = $pool > 0 ? min($value, round($value * $materialPool / $pool, 2)) : 0.0;

        return [
            'value' => $value,
            'material_portion' => $materialPortion,
            'overhead_applied' => $overheadApplied,
            'overhead_total' => $overheadTotal,
            'share' => $share,
            'pool' => $pool,
            'material' => $incurred['material'],
            'operation' => $incurred['operation'],
        ];
    }

    /**
     * What is left in progress when the order closes: the amount to write off to variance.
     * With work-in-progress accounting that is everything not carried into finished goods;
     * otherwise only the material that left inventory without reaching it.
     *
     * @return array{amount: float, total_residual: float, material_residual: float}
     */
    public function settlement(ProductionOrder $order): array
    {
        $incurred = $this->incurred($order);
        $totalResidual = round($incurred['material'] + $incurred['operation'] + (float) $order->actual_overhead_cost - (float) $order->capitalized_cost, 2);
        $materialResidual = round($incurred['material'] - (float) $order->capitalized_material, 2);

        return [
            'amount' => $order->wip_accounting ? $totalResidual : $materialResidual,
            'total_residual' => $totalResidual,
            'material_residual' => $materialResidual,
        ];
    }

    /**
     * Planned against actual, category by category. Planned figures are scaled to the
     * output made so far, so a half-finished order is not shown as a big saving.
     *
     * @return array<string, mixed>
     */
    public function breakdown(ProductionOrder $order): array
    {
        $incurred = $this->incurred($order);
        $made = $order->produced_qty_cartons + $order->rejected_qty_cartons;
        $scale = $order->status === 'completed' && $order->output_qty_cartons > 0
            ? ($made / $order->output_qty_cartons)
            : 1.0;

        $planned = [
            'material' => (float) $order->planned_material_cost * $scale,
            'operation' => (float) $order->planned_operation_cost * $scale,
            'overhead' => (float) $order->planned_overhead_cost * $scale,
        ];
        $actual = [
            'material' => $incurred['material'],
            'operation' => $incurred['operation'],
            'overhead' => (float) $order->actual_overhead_cost,
        ];

        $plannedTotal = array_sum($planned);
        $actualTotal = array_sum($actual);
        $good = $order->produced_qty_cartons;

        $round = fn (array $values) => array_map(fn ($v) => round($v, 2), $values);

        return [
            'planned' => $round($planned) + ['total' => round($plannedTotal, 2)],
            'actual' => $round($actual) + ['total' => round($actualTotal, 2)],
            'variance' => $round(array_map(fn ($k) => $actual[$k] - $planned[$k], array_combine(array_keys($planned), array_keys($planned)))) + ['total' => round($actualTotal - $plannedTotal, 2)],
            'scrap_cost' => $incurred['scrap'],
            'capitalized' => ['total' => (float) $order->capitalized_cost, 'material' => (float) $order->capitalized_material],
            'variance_written_off' => (float) $order->variance_cost,
            'good_qty_cartons' => $good,
            'unit_cost' => $good > 0 ? round((float) $order->capitalized_cost / $good, 2) : null,
            'planned_unit_cost' => $order->output_qty_cartons > 0 ? round(array_sum([(float) $order->planned_material_cost, (float) $order->planned_operation_cost, (float) $order->planned_overhead_cost]) / $order->output_qty_cartons, 2) : null,
        ];
    }

    /**
     * Share of the mandatory materials issued so far (by cartons), 1 when there are none.
     */
    private function materialProgress(ProductionOrder $order): float
    {
        $totals = $order->components()->where('optional', false)
            ->selectRaw('COALESCE(SUM(required_cartons), 0) as required, COALESCE(SUM(LEAST(consumed_cartons, required_cartons)), 0) as consumed')
            ->first();

        return $totals->required > 0 ? $totals->consumed / $totals->required : 1.0;
    }
}

<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Product;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Models\MrpRun;
use Modules\Manufacturing\Models\MrpSuggestion;
use Modules\Manufacturing\Models\ProductPlanning;
use Modules\Manufacturing\Support\LotSizer;

/**
 * Material requirements planning for one warehouse.
 *
 * What it does, in order:
 *  1. Gathers demand: open sales orders (draft/picking), open manual demands and forecasts, the
 *     materials still to be issued on open manufacturing orders (less what they have reserved),
 *     and — as it goes — the materials needed for the manufacturing orders it proposes itself.
 *  2. Gathers supply: free stock (on hand, not expired, not reserved), open purchase orders, and the
 *     output still to come from open manufacturing orders.
 *  3. Orders products from finished goods down (a product's own suggestions create demand for its
 *     components, so a component is only planned after everything that uses it). For each product it walks
 *     the dates, applies supply and demand in order, and whenever stock would fall below the safety
 *     level it plans a receipt sized by the product's lot rule. A reorder point tops stock up at the end.
 *  4. Turns each planned receipt into a suggestion: manufacture (exploding the recipe into demand for
 *     the components, a lead time earlier) or purchase.
 *
 * Re-running is safe: suggestions are identified by what they are for (type, product, warehouse, due
 * date), so a run updates the proposals already open instead of adding duplicates, and drops those that
 * are no longer needed. A suggestion that was turned into an order stops being proposed, and that order
 * counts as supply, so it is not suggested again.
 */
class MrpPlanner
{
    public function __construct(private readonly BomSelector $boms) {}

    /**
     * @return array<string, int>
     */
    public function plan(MrpRun $run): array
    {
        $tenantId = $run->tenant_id;
        $warehouseId = $run->inputs['warehouse_id'];
        $settings = ManufacturingSetting::forTenant($tenantId);
        $today = today();
        $horizonEnd = $today->copy()->addDays((int) ($run->inputs['horizon_days'] ?? $settings->mrp_horizon_days));

        $demand = $this->independentDemand($tenantId, $warehouseId, $horizonEnd, $today);
        $this->openOrderDemand($tenantId, $warehouseId, $horizonEnd, $today, $demand);
        $supply = $this->openSupply($tenantId, $warehouseId, $today);
        $replenished = $this->replenishmentProducts($tenantId);

        $universe = array_values(array_unique([...array_keys($demand), ...$replenished]));
        $planning = ProductPlanning::query()->where('tenant_id', $tenantId)->get()->keyBy('product_id');
        $boms = [];
        $levels = $this->levels($tenantId, $universe, $planning, $boms, $today);
        $products = Product::query()->where('tenant_id', $tenantId)->whereIn('id', array_keys($levels))->get()->keyBy('id');

        $stats = ['products' => 0, 'lines' => 0, 'manufacture' => 0, 'purchase' => 0, 'shortages' => 0, 'superseded' => 0, 'needs_supplier' => 0];
        $touched = [];

        $order = collect($levels)->sortBy(fn ($level, $id) => sprintf('%05d-%s', $level, $id))->keys();

        foreach ($order as $productId) {
            $product = $products[$productId] ?? null;

            if ($product === null || ! $product->active) {
                continue;
            }

            $plan = $planning[$productId] ?? null;
            $bom = $boms[$productId] ?? null;
            $make = $this->isMade($plan, $bom);
            $lead = $make ? ($plan?->lead_time_days ?: $settings->lead_time_days) : (int) ($plan?->lead_time_days ?? 0);
            $stock = $this->stockPosition($warehouseId, $productId);
            $safety = $plan?->make_to_order ? 0 : (int) $product->min_stock_cartons;
            $reorder = $plan?->make_to_order ? 0 : (int) $product->reorder_level;

            [$receipts, $projectedEnd] = $this->net($demand[$productId] ?? [], $supply[$productId] ?? [], $stock['free'], $safety, $plan);

            // Where stock ends up once everything planned so far has arrived: it must not be below the safety stock,
            // and once it is below the reorder point it is brought back up to that point.
            $afterPlan = $projectedEnd + array_sum(array_column($receipts, 'qty'));
            $target = $reorder > 0 && $afterPlan < $reorder ? max($safety, $reorder) : $safety;

            if ($afterPlan < $target) {
                foreach (LotSizer::lots($target - $afterPlan, $plan) as $lot) {
                    $receipts[] = ['date' => $today->toDateString(), 'qty' => $lot, 'reason' => $reorder > 0 && $afterPlan < $reorder ? 'reorder point' : 'safety stock'];
                }
            }

            $gross = collect($demand[$productId] ?? [])->sum('qty');
            $incoming = collect($supply[$productId] ?? [])->sum('qty');
            $planned = array_sum(array_column($receipts, 'qty'));

            if ($gross <= 0 && $planned <= 0) {
                continue;
            }

            $stats['products']++;
            $net = max(0.0, $gross + $safety - $stock['free'] - $incoming);

            foreach ($receipts as $index => $receipt) {
                $due = Carbon::parse($receipt['date']);
                $start = $due->copy()->subDays($lead);
                $late = $start->lt($today);
                $start = $late ? $today->copy() : $start;
                $sources = $this->topSources($demand[$productId] ?? [], $receipt['reason'] ?? null) + ['late' => $late];

                if ($make && $bom !== null) {
                    $this->addComponentDemand($bom, $receipt['qty'], $start, $productId, $demand);
                }

                $this->storeSuggestion($run, $tenantId, $warehouseId, $productId, $make && $bom !== null ? 'manufacture' : 'purchase', $receipt['qty'], $start, $due, $index, $product->supplier_id, $sources, $touched, $stats);

                if (! ($make && $bom !== null) && $product->supplier_id === null) {
                    $stats['needs_supplier']++;
                }
            }

            if ($planned > 0) {
                $stats['shortages']++;
            }

            $run->lines()->create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'level' => $levels[$productId],
                'gross_requirement' => round($gross, 4),
                'on_hand' => $stock['on_hand'],
                'reserved' => $stock['reserved'],
                'incoming' => round($incoming, 4),
                'safety_stock' => $safety,
                'net_requirement' => round($net, 4),
                'planned_qty' => $planned,
                'due_date' => $receipts[0]['date'] ?? null,
                'action' => $planned <= 0 ? 'none' : ($make && $bom !== null ? 'manufacture' : 'purchase'),
                'sources' => $this->topSources($demand[$productId] ?? [], null),
            ]);
            $stats['lines']++;
        }

        $stats['superseded'] = $this->supersedeUntouched($tenantId, $warehouseId, $touched);

        return $stats;
    }

    /**
     * Net the product over time. Returns the receipts to plan and the projected balance at the end of
     * the horizon before they arrive.
     *
     * @param  array<string, array{qty: float, sources: array<int, array<string, mixed>>}>  $demand
     * @param  array<int, array{date: string, qty: float}>  $supply
     * @return array{0: array<int, array{date: string, qty: int, reason?: string}>, 1: float}
     */
    private function net(array $demand, array $supply, int $free, int $safety, ?ProductPlanning $planning): array
    {
        $events = [];

        foreach ($supply as $row) {
            $events[] = [$row['date'], 0, (float) $row['qty']];     // supply first on the same day
        }
        foreach ($demand as $date => $row) {
            $events[] = [$date, 1, (float) $row['qty']];
        }

        usort($events, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $projected = (float) $free;
        $receipts = [];

        foreach ($events as [$date, $type, $qty]) {
            if ($type === 0) {
                $projected += $qty;

                continue;
            }

            $projected -= $qty;
            $short = $safety - $projected;

            if ($short > 1e-9) {
                foreach (LotSizer::lots($short, $planning) as $lot) {
                    $receipts[] = ['date' => $date, 'qty' => $lot, 'reason' => 'demand'];
                    $projected += $lot;
                }
            }
        }

        // Report the end balance as it was before the plan.
        return [$receipts, $projected - array_sum(array_column($receipts, 'qty'))];
    }

    /**
     * Demand that comes from outside: sales orders not yet delivered and manual demands / forecasts.
     *
     * @return array<string, array<string, array{qty: float, sources: array<int, array<string, mixed>>}>> product => date => demand
     */
    private function independentDemand(string $tenantId, string $warehouseId, Carbon $horizonEnd, Carbon $today): array
    {
        $demand = [];

        $cartons = 'CASE WHEN l.base_qty IS NOT NULL THEN CEIL((l.qty + l.free_qty) * l.base_qty / p.carton_qty) '
            ."WHEN l.unit = 'Carton' THEN l.qty + l.free_qty ELSE CEIL((l.qty + l.free_qty) / p.carton_qty) END";

        DB::table('sales_order_lines as l')
            ->join('sales_orders as o', 'o.id', '=', 'l.so_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('o.tenant_id', $tenantId)
            ->whereIn('o.status', ['draft', 'picking'])
            ->where('o.stock_deducted', false)
            ->where('l.is_gift', false)
            ->whereRaw('COALESCE(l.warehouse_id, o.warehouse_id) = ?', [$warehouseId])
            ->whereDate('o.order_date', '<=', $horizonEnd->toDateString())
            ->selectRaw("l.product_id, o.id as order_id, o.order_date, SUM({$cartons}) as qty")
            ->groupBy('l.product_id', 'o.id', 'o.order_date')
            ->orderBy('o.order_date')
            ->get()
            ->each(function ($row) use (&$demand, $today) {
                $this->addDemand($demand, $row->product_id, $this->clamp($row->order_date, $today), (float) $row->qty, ['type' => 'sales_order', 'id' => $row->order_id]);
            });

        DB::table('mrp_demands')
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('status', 'open')
            ->whereDate('due_date', '<=', $horizonEnd->toDateString())
            ->get(['id', 'product_id', 'qty_cartons', 'due_date', 'kind'])
            ->each(function ($row) use (&$demand, $today) {
                $this->addDemand($demand, $row->product_id, $this->clamp($row->due_date, $today), (float) $row->qty_cartons, ['type' => 'mrp_demand', 'id' => $row->id, 'kind' => $row->kind]);
            });

        return $demand;
    }

    /**
     * Materials the open manufacturing orders of the warehouse still have to take from stock.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $demand
     */
    private function openOrderDemand(string $tenantId, string $warehouseId, Carbon $horizonEnd, Carbon $today, array &$demand): void
    {
        // Confirmed orders: what is still to issue, less what they already hold reserved.
        DB::table('production_order_components as c')
            ->join('production_orders as o', 'o.id', '=', 'c.production_order_id')
            ->where('o.tenant_id', $tenantId)->where('o.warehouse_id', $warehouseId)
            ->whereIn('o.status', ['confirmed', 'planned', 'in_progress', 'paused'])
            ->where('c.optional', false)
            ->whereRaw('c.required_cartons > c.consumed_cartons + c.reserved_cartons')
            ->selectRaw('c.product_id, o.id as order_id, o.number, COALESCE(o.planned_start, o.production_date) as due, (c.required_cartons - c.consumed_cartons - c.reserved_cartons) as qty')
            ->get()
            ->each(function ($row) use (&$demand, $today) {
                $this->addDemand($demand, $row->product_id, $this->clamp($row->due, $today), (float) $row->qty, ['type' => 'production_order', 'id' => $row->order_id, 'number' => $row->number]);
            });

        // Draft orders have no components yet: work them out from the recipe.
        DB::table('production_orders as o')
            ->where('o.tenant_id', $tenantId)->where('o.warehouse_id', $warehouseId)->where('o.status', 'draft')
            ->get(['o.id', 'o.number', 'o.bom_id', 'o.output_qty_cartons', 'o.planned_start', 'o.production_date'])
            ->each(function ($order) use (&$demand, $today) {
                $bom = Bom::query()->with('lines')->find($order->bom_id);

                foreach ($bom?->lines ?? [] as $line) {
                    if ($line->optional) {
                        continue;
                    }

                    $qty = round($line->qty_cartons * $order->output_qty_cartons / max(1, $bom->output_qty_cartons) * (1 + (float) $line->scrap_pct / 100), 4);
                    $this->addDemand($demand, $line->component_product_id, $this->clamp($order->planned_start ?? $order->production_date, $today), $qty, ['type' => 'production_order', 'id' => $order->id, 'number' => $order->number]);
                }
            });
    }

    /**
     * Supply on its way: open purchase orders and the output still to come from open manufacturing orders.
     *
     * @return array<string, array<int, array{date: string, qty: float}>>
     */
    private function openSupply(string $tenantId, string $warehouseId, Carbon $today): array
    {
        $supply = [];

        DB::table('purchase_order_lines as l')
            ->join('purchase_orders as o', 'o.id', '=', 'l.po_id')
            ->where('o.tenant_id', $tenantId)->where('o.warehouse_id', $warehouseId)
            ->whereNotIn('o.status', ['received', 'cancelled'])
            ->selectRaw('l.product_id, COALESCE(o.expected_date, o.order_date) as due, SUM(l.qty_cartons) as qty')
            ->groupBy('l.product_id', DB::raw('COALESCE(o.expected_date, o.order_date)'))
            ->get()
            ->each(function ($row) use (&$supply, $today) {
                $supply[$row->product_id][] = ['date' => $this->clamp($row->due, $today), 'qty' => (float) $row->qty];
            });

        DB::table('production_orders as o')
            ->join('boms as b', 'b.id', '=', 'o.bom_id')
            ->where('o.tenant_id', $tenantId)->where('o.warehouse_id', $warehouseId)
            ->whereIn('o.status', ['draft', 'confirmed', 'planned', 'in_progress', 'paused'])
            ->whereRaw('o.output_qty_cartons > o.produced_qty_cartons + o.rejected_qty_cartons')
            ->selectRaw('b.product_id, COALESCE(o.planned_finish, o.planned_start, o.production_date) as due, (o.output_qty_cartons - o.produced_qty_cartons - o.rejected_qty_cartons) as qty')
            ->get()
            ->each(function ($row) use (&$supply, $today) {
                $supply[$row->product_id][] = ['date' => $this->clamp($row->due, $today), 'qty' => (float) $row->qty];
            });

        return $supply;
    }

    /**
     * Products with a safety stock or a reorder point, which are planned even with no demand.
     *
     * @return array<int, string>
     */
    private function replenishmentProducts(string $tenantId): array
    {
        return Product::query()
            ->where('tenant_id', $tenantId)->where('active', true)
            ->where(fn ($q) => $q->where('min_stock_cartons', '>', 0)->orWhere('reorder_level', '>', 0))
            ->pluck('id')->all();
    }

    /**
     * How deep in the product structure each product sits (finished goods at 0, their components 1, ...), found by
     * following the recipes of the products that are made. Fills `$boms` with the recipe chosen for each made product.
     *
     * @param  array<int, string>  $start
     * @param  Collection<string, ProductPlanning>  $planning
     * @param  array<string, Bom>  $boms
     * @return array<string, int>
     */
    private function levels(string $tenantId, array $start, Collection $planning, array &$boms, Carbon $today): array
    {
        $edges = [];
        $seen = [];
        $queue = $start;

        while ($queue !== []) {
            $product = array_pop($queue);

            if (isset($seen[$product])) {
                continue;
            }
            $seen[$product] = true;

            $bom = $this->boms->forProduct($tenantId, $product, $today);

            if ($bom === null || ! $this->isMade($planning[$product] ?? null, $bom)) {
                continue;
            }

            $boms[$product] = $bom;
            foreach ($bom->lines()->where('optional', false)->pluck('component_product_id') as $child) {
                $edges[$product][] = $child;
                $queue[] = $child;
            }
        }

        $levels = array_fill_keys(array_keys($seen), 0);

        // Longest path from a root, by repeated relaxation (the graph has no loops, so this ends within |V| passes).
        for ($pass = 0; $pass <= count($seen); $pass++) {
            $changed = false;

            foreach ($edges as $parent => $children) {
                foreach ($children as $child) {
                    if ($levels[$child] < $levels[$parent] + 1) {
                        $levels[$child] = $levels[$parent] + 1;
                        $changed = true;
                    }
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $levels;
    }

    private function isMade(?ProductPlanning $planning, ?Bom $bom): bool
    {
        return match ($planning?->procurement) {
            'make' => $bom !== null,
            'buy' => false,
            default => $bom !== null,
        };
    }

    /**
     * @return array{on_hand: int, reserved: int, free: int}
     */
    private function stockPosition(string $warehouseId, string $productId): array
    {
        $row = DB::table('inventory_batches')
            ->where('warehouse_id', $warehouseId)->where('product_id', $productId)->where('is_free_gift', false)
            ->whereDate('exp_date', '>=', today())->where('qty_cartons', '>', 0)
            ->selectRaw('COALESCE(SUM(qty_cartons), 0) as on_hand, COALESCE(SUM(reserved_cartons), 0) as reserved, COALESCE(SUM(GREATEST(qty_cartons - reserved_cartons, 0)), 0) as free')
            ->first();

        return ['on_hand' => (int) $row->on_hand, 'reserved' => (int) $row->reserved, 'free' => (int) $row->free];
    }

    /**
     * Demand for the components of a recipe made `$qty` cartons at a time.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $demand
     */
    private function addComponentDemand(Bom $bom, int $qty, Carbon $date, string $parentProductId, array &$demand): void
    {
        $runs = $qty / max(1, $bom->output_qty_cartons);

        foreach ($bom->lines()->where('optional', false)->get() as $line) {
            $need = round($line->qty_cartons * $runs * (1 + (float) $line->scrap_pct / 100), 4);
            $this->addDemand($demand, $line->component_product_id, $date->toDateString(), $need, ['type' => 'planned_order', 'product_id' => $parentProductId]);
        }
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $demand
     * @param  array<string, mixed>  $source
     */
    private function addDemand(array &$demand, string $productId, string $date, float $qty, array $source): void
    {
        $demand[$productId][$date]['qty'] = ($demand[$productId][$date]['qty'] ?? 0.0) + $qty;
        $demand[$productId][$date]['sources'][] = $source + ['qty' => round($qty, 4)];
    }

    /**
     * @param  array<string, array{qty: float, sources: array<int, array<string, mixed>>}>  $buckets
     * @return array<string, mixed>
     */
    private function topSources(array $buckets, ?string $reason): array
    {
        $sources = collect($buckets)->flatMap(fn ($b) => $b['sources'])->take(20)->values()->all();

        return array_filter(['demand' => $sources, 'reason' => $reason]);
    }

    private function clamp(string $date, Carbon $today): string
    {
        $day = Carbon::parse($date)->startOfDay();

        return ($day->lt($today) ? $today : $day)->toDateString();
    }

    /**
     * Creates or refreshes the open suggestion for this need. Returns nothing; records it as touched.
     *
     * @param  array<string, mixed>  $sources
     * @param  array<int, string>  $touched
     * @param  array<string, int>  $stats
     */
    private function storeSuggestion(MrpRun $run, string $tenantId, string $warehouseId, string $productId, string $type, int $qty, Carbon $start, Carbon $due, int $chunk, ?string $supplierId, array $sources, array &$touched, array &$stats): void
    {
        $fingerprint = sha1("{$type}|{$productId}|{$warehouseId}|{$due->toDateString()}|{$chunk}");
        $touched[] = $fingerprint;

        $existing = MrpSuggestion::query()->where('tenant_id', $tenantId)->where('open_key', $fingerprint)->first();

        if ($existing !== null) {
            // A dismissed suggestion stays dismissed while the same need remains.
            $existing->update(array_filter([
                'mrp_run_id' => $run->id,
                'qty_cartons' => $existing->status === 'proposed' ? $qty : null,
                'start_date' => $existing->status === 'proposed' ? $start : null,
                'supplier_id' => $existing->status === 'proposed' ? $supplierId : null,
                'sources' => $sources,
            ], fn ($v) => $v !== null));

            if ($existing->status === 'proposed') {
                $stats[$type]++;
            }

            return;
        }

        MrpSuggestion::create([
            'tenant_id' => $tenantId, 'mrp_run_id' => $run->id, 'type' => $type, 'product_id' => $productId, 'warehouse_id' => $warehouseId,
            'qty_cartons' => $qty, 'start_date' => $start, 'due_date' => $due, 'supplier_id' => $type === 'purchase' ? $supplierId : null,
            'status' => 'proposed', 'fingerprint' => $fingerprint, 'open_key' => $fingerprint, 'sources' => $sources,
        ]);
        $stats[$type]++;
    }

    /**
     * Open suggestions the run no longer asks for are withdrawn.
     *
     * @param  array<int, string>  $touched
     */
    private function supersedeUntouched(string $tenantId, string $warehouseId, array $touched): int
    {
        return MrpSuggestion::query()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->whereIn('status', ['proposed', 'dismissed'])
            ->whereNotIn('fingerprint', $touched === [] ? [''] : $touched)
            ->update(['status' => 'superseded', 'open_key' => null]);
    }
}

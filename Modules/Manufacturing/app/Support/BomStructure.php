<?php

namespace Modules\Manufacturing\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\Product;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Services\BomSelector;

/**
 * Reads the recipes of a company as a graph: which products are made of which.
 * Keeps it a graph without loops, and walks it to list every material a
 * quantity of a product ultimately needs.
 */
class BomStructure
{
    public function __construct(private readonly BomSelector $selector) {}

    /**
     * Refuses a recipe that would make `$productId` (indirectly) a component of itself.
     *
     * @param  array<int, string>  $componentIds  The components of the recipe being saved.
     * @param  string|null  $ignoreBomId  The recipe being edited: its stored lines are about to be replaced.
     */
    public function assertNoCycle(string $tenantId, string $productId, array $componentIds, ?string $ignoreBomId = null): void
    {
        $graph = $this->adjacency($tenantId, $ignoreBomId);

        foreach ($componentIds as $start) {
            $path = $this->pathTo($graph, $start, $productId);

            if ($path !== null) {
                $names = Product::query()->whereIn('id', [$productId, ...$path])->pluck('name', 'id');
                $trail = collect([$productId, ...$path, $productId])->map(fn ($id) => $names[$id] ?? $id)->implode(' → ');

                throw ValidationException::withMessages(['lines' => "This recipe would make a product a component of itself ({$trail})."]);
            }
        }
    }

    /**
     * Every material needed to make `$outputCartons` of the BoM's product, sub-assemblies
     * expanded through their own recipes. Rows come parents-first.
     *
     * @return array<int, array{level: int, product_id: string, parent_product_id: string, qty: float, optional: bool, bom_id: ?string, kind: string}>
     */
    public function explode(Bom $bom, float $outputCartons, int $maxDepth = 12): array
    {
        $rows = [];
        $this->walk($bom, $outputCartons, 1, [$bom->product_id], $rows, $maxDepth);

        return $rows;
    }

    /**
     * @param  array<int, string>  $stack  Products on the way down, to stop a loop that slipped past validation.
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function walk(Bom $bom, float $outputCartons, int $level, array $stack, array &$rows, int $maxDepth): void
    {
        $bom->loadMissing('lines');
        $runs = $outputCartons / max(1, $bom->output_qty_cartons);

        foreach ($bom->lines as $line) {
            $qty = round($line->qty_cartons * $runs * (1 + (float) $line->scrap_pct / 100), 4);
            $child = in_array($line->component_product_id, $stack, true) || $level >= $maxDepth
                ? null
                : $this->selector->forProduct($bom->tenant_id, $line->component_product_id);

            $rows[] = [
                'level' => $level,
                'product_id' => $line->component_product_id,
                'parent_product_id' => $bom->product_id,
                'qty' => $qty,
                'optional' => (bool) $line->optional,
                'bom_id' => $child?->id,
                'kind' => $child === null ? 'material' : 'assembly',
            ];

            if ($child !== null) {
                $this->walk($child, $qty, $level + 1, [...$stack, $line->component_product_id], $rows, $maxDepth);
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function adjacency(string $tenantId, ?string $ignoreBomId): array
    {
        $graph = [];

        DB::table('bom_lines')
            ->join('boms', 'boms.id', '=', 'bom_lines.bom_id')
            ->where('boms.tenant_id', $tenantId)
            ->where('boms.active', true)
            ->when($ignoreBomId, fn ($query) => $query->where('boms.id', '!=', $ignoreBomId))
            ->get(['boms.product_id as parent', 'bom_lines.component_product_id as child'])
            ->each(function ($row) use (&$graph) {
                $graph[$row->parent][] = $row->child;
            });

        return $graph;
    }

    /**
     * The path from `$start` down to `$target` (excluding `$target`), or null if none.
     *
     * @param  array<string, array<int, string>>  $graph
     * @return array<int, string>|null
     */
    private function pathTo(array $graph, string $start, string $target): ?array
    {
        $stack = [[$start, [$start]]];
        $seen = [];

        while ($stack !== []) {
            [$node, $path] = array_pop($stack);

            if ($node === $target) {
                return array_slice($path, 0, -1);
            }

            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;

            foreach ($graph[$node] ?? [] as $child) {
                $stack[] = [$child, [...$path, $child]];
            }
        }

        return null;
    }
}

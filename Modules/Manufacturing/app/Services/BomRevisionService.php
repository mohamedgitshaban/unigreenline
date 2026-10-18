<?php

namespace Modules\Manufacturing\Services;

use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomRevision;

/**
 * Writes the immutable snapshots that let an order show exactly which version
 * of a recipe it was planned against, long after the recipe has moved on.
 */
class BomRevisionService
{
    /**
     * Stores the BoM as it is now under its current version number. A version is
     * only ever written once: asking again returns the snapshot already there.
     */
    public function snapshot(Bom $bom, ?string $note = null, ?string $userId = null): BomRevision
    {
        $existing = BomRevision::query()->where('bom_id', $bom->id)->where('revision_no', $bom->version)->first();

        if ($existing !== null) {
            return $existing;
        }

        return BomRevision::create([
            'bom_id' => $bom->id,
            'revision_no' => $bom->version,
            'snapshot' => $this->capture($bom),
            'change_note' => $note,
            'created_by' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function capture(Bom $bom): array
    {
        $bom->load(['lines', 'byProducts', 'routing.operations']);

        return [
            'code' => $bom->code,
            'name' => $bom->name,
            'version' => $bom->version,
            'product_id' => $bom->product_id,
            'output_qty_cartons' => $bom->output_qty_cartons,
            'conversion_cost' => (string) $bom->conversion_cost,
            'priority' => $bom->priority,
            'effective_from' => $bom->effective_from?->toDateString(),
            'effective_to' => $bom->effective_to?->toDateString(),
            'routing_id' => $bom->routing_id,
            'notes' => $bom->notes,
            'instructions' => $bom->instructions,
            'lines' => $bom->lines->map(fn ($line) => [
                'component_product_id' => $line->component_product_id,
                'qty_cartons' => $line->qty_cartons,
                'scrap_pct' => (string) $line->scrap_pct,
                'optional' => (bool) $line->optional,
                'unit_id' => $line->unit_id,
                'unit_qty' => $line->unit_qty,
                'operation_id' => $line->operation_id,
            ])->values()->all(),
            'by_products' => $bom->byProducts->map(fn ($byProduct) => [
                'product_id' => $byProduct->product_id,
                'qty_cartons' => $byProduct->qty_cartons,
                'cost_share_pct' => (string) $byProduct->cost_share_pct,
                'warehouse_id' => $byProduct->warehouse_id,
            ])->values()->all(),
            'operations' => $bom->routing?->operations->map(fn ($operation) => [
                'id' => $operation->id,
                'sequence' => $operation->sequence,
                'name' => $operation->name,
                'work_center_id' => $operation->work_center_id,
                'setup_minutes' => $operation->setup_minutes,
                'run_minutes' => (string) $operation->run_minutes,
                'cleanup_minutes' => $operation->cleanup_minutes,
                'depends_on_id' => $operation->depends_on_id,
            ])->values()->all() ?? [],
        ];
    }
}

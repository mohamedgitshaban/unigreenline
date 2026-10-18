<?php

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'version' => $this->version,
            'status' => $this->status,
            'priority' => $this->priority,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'product' => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku] : null,
            'routing' => $this->whenLoaded('routing', fn () => $this->routing ? ['id' => $this->routing->id, 'code' => $this->routing->code, 'name' => $this->routing->name] : null),
            'output_qty_cartons' => $this->output_qty_cartons,
            'conversion_cost' => $this->conversion_cost,
            'notes' => $this->notes,
            'instructions' => $this->instructions,
            'active' => $this->active,
            'approved_at' => $this->approved_at,
            'archived_at' => $this->archived_at,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'component' => $line->component ? ['id' => $line->component->id, 'name' => $line->component->name, 'sku' => $line->component->sku] : null,
                'component_product_id' => $line->component_product_id,
                'qty_cartons' => $line->qty_cartons,
                'scrap_pct' => $line->scrap_pct,
                'optional' => $line->optional,
                'unit_id' => $line->unit_id,
                'unit_qty' => $line->unit_qty,
                'operation_id' => $line->operation_id,
            ])->values()),
            'by_products' => $this->whenLoaded('byProducts', fn () => $this->byProducts->map(fn ($byProduct) => [
                'id' => $byProduct->id,
                'product' => $byProduct->product ? ['id' => $byProduct->product->id, 'name' => $byProduct->product->name, 'sku' => $byProduct->product->sku] : null,
                'product_id' => $byProduct->product_id,
                'qty_cartons' => $byProduct->qty_cartons,
                'cost_share_pct' => $byProduct->cost_share_pct,
                'warehouse_id' => $byProduct->warehouse_id,
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}

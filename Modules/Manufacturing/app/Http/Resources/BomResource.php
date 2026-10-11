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
            'name' => $this->name,
            'product' => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku] : null,
            'output_qty_cartons' => $this->output_qty_cartons,
            'conversion_cost' => $this->conversion_cost,
            'notes' => $this->notes,
            'active' => $this->active,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'component' => $line->component ? ['id' => $line->component->id, 'name' => $line->component->name, 'sku' => $line->component->sku] : null,
                'component_product_id' => $line->component_product_id,
                'qty_cartons' => $line->qty_cartons,
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}

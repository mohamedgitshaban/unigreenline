<?php

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bom' => $this->bom ? ['id' => $this->bom->id, 'name' => $this->bom->name, 'product_id' => $this->bom->product_id] : null,
            'warehouse' => $this->warehouse ? ['id' => $this->warehouse->id, 'name' => $this->warehouse->name] : null,
            'status' => $this->status,
            'runs' => $this->runs,
            'output_qty_cartons' => $this->output_qty_cartons,
            'production_date' => $this->production_date->toDateString(),
            'batch_no' => $this->batch_no,
            'mfg_date' => $this->mfg_date?->toDateString(),
            'exp_date' => $this->exp_date->toDateString(),
            'conversion_cost' => $this->conversion_cost,
            'material_cost' => $this->material_cost,
            'cost_per_carton' => $this->cost_per_carton,
            'output_batch_id' => $this->output_batch_id,
            'journal_entry_id' => $this->journal_entry_id,
            'notes' => $this->notes,
            'consumptions' => $this->whenLoaded('consumptions', fn () => $this->consumptions->map(fn ($c) => [
                'product_id' => $c->product_id,
                'batch_id' => $c->batch_id,
                'batch_no' => $c->batch_no,
                'qty_cartons' => $c->qty_cartons,
                'cost_per_carton' => $c->cost_per_carton,
                'line_value' => $c->line_value,
            ])->values()),
            'created_by' => $this->createdBy ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name] : null,
            'completed_by' => $this->completedBy ? ['id' => $this->completedBy->id, 'name' => $this->completedBy->name] : null,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}

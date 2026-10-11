<?php

namespace Modules\Recap\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecapLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'batch_no' => $this->batch_no,
            'product' => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku] : null,
            'exp_date' => $this->exp_date->toDateString(),
            'qty_cartons' => $this->qty_cartons,
            'cost_per_carton' => $this->cost_per_carton,
            'line_value' => $this->line_value,
        ];
    }
}

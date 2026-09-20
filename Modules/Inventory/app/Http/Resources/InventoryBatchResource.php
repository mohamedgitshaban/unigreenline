<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'warehouse_id' => $this->warehouse_id,
            'batch_no' => $this->batch_no,
            'mfg_date' => $this->mfg_date?->toDateString(),
            'exp_date' => $this->exp_date->toDateString(),
            'rcv_date' => $this->rcv_date->toDateString(),
            'qty_cartons' => $this->qty_cartons,
            'qty_packs' => $this->qty_packs,
            'cost_per_carton' => $this->cost_per_carton,
        ];
    }
}

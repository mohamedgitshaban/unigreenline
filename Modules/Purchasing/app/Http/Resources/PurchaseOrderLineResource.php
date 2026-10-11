<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\ProductResource;

class PurchaseOrderLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => ProductResource::make($this->product),
            'unit_id' => $this->unit_id,
            'unit_qty' => $this->unit_qty,
            'cost_per_unit' => $this->cost_per_unit,
            'qty_cartons' => $this->qty_cartons,
            'cost_per_carton' => $this->cost_per_carton,
            'total' => $this->total,
        ];
    }
}

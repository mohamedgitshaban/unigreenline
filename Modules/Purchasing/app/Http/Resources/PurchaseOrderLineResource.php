<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'qty_cartons' => $this->qty_cartons,
            'cost_per_carton' => $this->cost_per_carton,
            'total' => $this->total,
        ];
    }
}

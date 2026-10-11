<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\ProductResource;

class SalesOrderLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => ProductResource::make($this->product),
            'warehouse_id' => $this->warehouse_id ?? $this->salesOrder?->warehouse_id,
            'batch_no' => $this->batch_no,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'unit_id' => $this->unit_id,
            'base_qty' => $this->base_qty,
            'is_gift' => (bool) $this->is_gift,
            'unit_price' => $this->unit_price,
            'discount_pct' => $this->discount_pct,
            'free_qty' => $this->free_qty,
            'subtotal' => $this->subtotal,
        ];
    }
}

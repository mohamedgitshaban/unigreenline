<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\WarehouseResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier' => SupplierResource::make($this->supplier),
            'warehouse' => WarehouseResource::make($this->warehouse),
            'created_by' => $this->created_by,
            'status' => $this->status,
            'stock_added' => $this->stock_added,
            'order_date' => $this->order_date->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'received_date' => $this->received_date?->toDateString(),
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'notes' => $this->notes,
            'lines' => PurchaseOrderLineResource::collection($this->lines),
        ];
    }
}

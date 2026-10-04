<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\UserResource;

class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => ProductResource::make($this->product),
            'from_warehouse' => WarehouseResource::make($this->fromWarehouse),
            'to_warehouse' => WarehouseResource::make($this->toWarehouse),
            'batch_no' => $this->batch_no,
            'qty_cartons' => $this->qty_cartons,
            'transfer_date' => $this->transfer_date->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,
            'created_by' => UserResource::make($this->createdBy),
        ];
    }
}

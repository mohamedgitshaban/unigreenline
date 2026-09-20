<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'governorate' => $this->governorate,
            'address' => $this->address,
            'manager_id' => $this->manager_id,
            'manager_name' => $this->manager_name,
            'temperature' => $this->temperature,
            'capacity' => $this->capacity,
            'phone' => $this->phone,
            'status' => $this->status,
            'stock_value' => $this->stock_value,
            'batches' => InventoryBatchResource::collection($this->whenLoaded('batches')),
        ];
    }
}

<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'from_warehouse_id' => $this->from_warehouse_id,
            'to_warehouse_id' => $this->to_warehouse_id,
            'batch_no' => $this->batch_no,
            'qty_cartons' => $this->qty_cartons,
            'transfer_date' => $this->transfer_date->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,
            'created_by' => $this->created_by,
        ];
    }
}

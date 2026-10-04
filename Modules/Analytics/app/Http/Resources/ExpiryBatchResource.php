<?php

namespace Modules\Analytics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Http\Resources\WarehouseResource;

class ExpiryBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'batch_id' => $this->id,
            'product' => ProductResource::make($this->product),
            'warehouse' => WarehouseResource::make($this->warehouse),
            'batch_no' => $this->batch_no,
            'exp_date' => $this->exp_date->toDateString(),
            'qty_cartons' => $this->qty_cartons,
            // Positive = days until expiry, 0 = expires today, negative =
            // already expired. Plain date-timestamp math, not Carbon's
            // diffInDays — its signed-direction argument is easy to get
            // backwards, and this is unambiguous either way.
            'days_left' => (int) ceil((strtotime($this->exp_date->toDateString()) - strtotime(now()->toDateString())) / 86400),
        ];
    }
}

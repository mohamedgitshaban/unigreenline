<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'so_id' => $this->so_id,
            'invoice_id' => $this->invoice_id,
            'customer_id' => $this->customer_id,
            'driver' => $this->driver,
            'delivery_date' => $this->delivery_date?->toDateString(),
            'status' => $this->status,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}

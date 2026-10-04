<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Http\Resources\CustomerResource;

class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_order' => SalesOrderResource::make($this->salesOrder),
            'invoice' => InvoiceResource::make($this->invoice),
            'customer' => CustomerResource::make($this->customer),
            'driver' => $this->driver,
            'delivery_date' => $this->delivery_date?->toDateString(),
            'status' => $this->status,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}

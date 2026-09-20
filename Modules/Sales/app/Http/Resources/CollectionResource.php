<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'customer_id' => $this->customer_id,
            'collected_by' => $this->collected_by,
            'amount' => $this->amount,
            'method' => $this->method,
            'reference' => $this->reference,
            'payment_date' => $this->payment_date->toDateString(),
            'notes' => $this->notes,
        ];
    }
}

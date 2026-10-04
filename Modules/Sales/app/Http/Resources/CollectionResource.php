<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\UserResource;
use Modules\CRM\Http\Resources\CustomerResource;

class CollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice' => InvoiceResource::make($this->invoice),
            'customer' => CustomerResource::make($this->customer),
            'collected_by' => UserResource::make($this->collectedBy),
            'amount' => $this->amount,
            'method' => $this->method,
            'reference' => $this->reference,
            'payment_date' => $this->payment_date->toDateString(),
            'notes' => $this->notes,
        ];
    }
}

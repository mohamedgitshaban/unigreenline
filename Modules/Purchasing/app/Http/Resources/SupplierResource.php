<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'country' => $this->country,
            'city' => $this->city,
            'contact' => $this->contact,
            'email' => $this->email,
            'phone' => $this->phone,
            'pay_terms' => $this->pay_terms,
            'currency' => $this->currency,
            'balance' => $this->balance,
            'rating' => $this->rating,
            'address' => $this->address,
            'contract_data' => $this->contract_data,
            'contract_url' => $this->contract_data !== null ? url("/api/v1/suppliers/{$this->id}/contract") : null,
            'status' => $this->status,
        ];
    }
}

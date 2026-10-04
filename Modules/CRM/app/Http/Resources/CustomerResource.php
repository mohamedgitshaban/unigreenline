<?php

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\UserResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_rep' => UserResource::make($this->salesRep),
            'name' => $this->name,
            'type' => $this->type,
            'classification' => $this->classification,
            'phone' => $this->phone,
            'email' => $this->email,
            'governorate' => $this->governorate,
            'province' => $this->province,
            'city' => $this->city,
            'area' => $this->area,
            'address' => $this->address,
            'credit_limit' => $this->credit_limit,
            'pay_terms' => $this->pay_terms,
            'balance' => $this->balance,
            'status' => $this->status,
            'visits' => CustomerVisitResource::collection($this->whenLoaded('visits')),
            // orders/invoices aren't Eloquent relations on this model (Sales
            // already depends on CRM for Customer; the reverse would create
            // a cycle) — the controller attaches them as plain attributes
            // for the show endpoint only.
            'orders' => $this->when(
                array_key_exists('orders', $this->resource->getAttributes()),
                fn () => $this->orders
            ),
            'invoices' => $this->when(
                array_key_exists('invoices', $this->resource->getAttributes()),
                fn () => $this->invoices
            ),
        ];
    }
}

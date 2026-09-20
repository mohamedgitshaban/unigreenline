<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'so_id' => $this->so_id,
            'customer_id' => $this->customer_id,
            'issued_date' => $this->issued_date->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'paid' => $this->paid,
            'balance' => $this->balance,
            'status' => $this->status,
            // Invoices have no line items of their own — they mirror the
            // originating sales order's lines (spec has no invoice_lines table).
            'lines' => SalesOrderLineResource::collection($this->whenLoaded('salesOrder', fn () => $this->salesOrder->lines)),
            'collections' => CollectionResource::collection($this->whenLoaded('collections')),
        ];
    }
}

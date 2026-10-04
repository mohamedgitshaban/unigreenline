<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Http\Resources\CustomerResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_order' => SalesOrderResource::make($this->salesOrder),
            'customer' => CustomerResource::make($this->customer),
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
            // Only when the controller eager-loaded `salesOrder.lines` — the nested
            // sales_order above lazy-loads salesOrder, so whenLoaded('salesOrder')
            // alone would no longer be a signal that lines were asked for.
            'lines' => SalesOrderLineResource::collection($this->when(
                (bool) $this->salesOrder?->relationLoaded('lines'),
                fn () => $this->salesOrder->lines
            )),
            'collections' => CollectionResource::collection($this->whenLoaded('collections')),
        ];
    }
}

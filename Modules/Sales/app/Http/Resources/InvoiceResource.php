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
            // Paginated at GET /invoices/{id}/lines and /invoices/{id}/collections.
            'lines_count' => $this->whenCounted('lines'),
            'collections_count' => $this->whenCounted('collections'),
        ];
    }
}

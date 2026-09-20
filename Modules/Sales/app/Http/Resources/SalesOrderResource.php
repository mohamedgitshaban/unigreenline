<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'sales_rep_id' => $this->sales_rep_id,
            'warehouse_id' => $this->warehouse_id,
            'status' => $this->status,
            'pay_type' => $this->pay_type,
            'grace_period' => $this->grace_period,
            'due_date' => $this->due_date?->toDateString(),
            'invoice_discount' => $this->invoice_discount,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'stock_deducted' => $this->stock_deducted,
            'notes' => $this->notes,
            'order_date' => $this->order_date->toDateString(),
            'lines' => SalesOrderLineResource::collection($this->whenLoaded('lines')),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
            // hasOne, and doesn't exist until the order reaches `delivered`
            // (UpdateSalesOrderStatusService::upsertDelivery) — eager-loaded
            // but genuinely null before that, so this can't just be
            // `new DeliveryResource($this->whenLoaded('delivery'))`: wrapping
            // a loaded-but-null relation in a Resource crashes when toArray()
            // reads a property off it, it only degrades gracefully when the
            // relation was never loaded at all.
            'delivery' => $this->whenLoaded(
                'delivery',
                fn () => $this->delivery ? new DeliveryResource($this->delivery) : null
            ),
        ];
    }
}

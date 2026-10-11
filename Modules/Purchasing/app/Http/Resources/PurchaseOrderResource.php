<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\UserResource;
use Modules\Inventory\Http\Resources\WarehouseResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier' => SupplierResource::make($this->supplier),
            'warehouse' => WarehouseResource::make($this->warehouse),
            'created_by' => UserResource::make($this->createdBy),
            'status' => $this->status,
            'stock_added' => $this->stock_added,
            'order_date' => $this->order_date->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'received_date' => $this->received_date?->toDateString(),
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'notes' => $this->notes,
            // Recorded on receive; null until then (or if none was sent).
            'supplier_invoice' => $this->supplier_invoice_no !== null || $this->supplier_invoice_path !== null ? [
                'invoice_no' => $this->supplier_invoice_no,
                'invoice_date' => $this->supplier_invoice_date?->toDateString(),
                'amount' => $this->supplier_invoice_amount,
                'file_path' => $this->supplier_invoice_path,
                'file_url' => $this->supplier_invoice_path !== null ? url("/api/v1/purchase-orders/{$this->id}/invoice") : null,
            ] : null,
            // Paginated at GET /purchase-orders/{id}/lines.
            'lines_count' => $this->whenCounted('lines'),
        ];
    }
}

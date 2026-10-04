<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Http\Resources\CustomerResource;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Http\Resources\WarehouseResource;
use Modules\Purchasing\Http\Resources\SupplierResource;

class ReturnRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice' => InvoiceResource::make($this->invoice),
            'customer' => CustomerResource::make($this->customer),
            'supplier' => SupplierResource::make($this->supplier),
            'product' => ProductResource::make($this->product),
            'warehouse' => WarehouseResource::make($this->warehouse),
            'batch_no' => $this->batch_no,
            'type' => $this->type,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'amount' => $this->amount,
            'restocked' => $this->restocked,
            'reason' => $this->reason,
            'status' => $this->status,
            'return_date' => $this->return_date->toDateString(),
        ];
    }
}

<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'customer_id' => $this->customer_id,
            'supplier_id' => $this->supplier_id,
            'product_id' => $this->product_id,
            'warehouse_id' => $this->warehouse_id,
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

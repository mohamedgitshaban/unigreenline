<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier' => $this->supplier ? ['id' => $this->supplier->id, 'name' => $this->supplier->name] : null,
            'purchase_order_id' => $this->purchase_order_id,
            'amount' => $this->amount,
            'method' => $this->method,
            'payment_date' => $this->payment_date->toDateString(),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'created_by' => $this->createdBy ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name] : null,
            'journal_entry_id' => $this->journal_entry_id,
            'created_at' => $this->created_at,
        ];
    }
}

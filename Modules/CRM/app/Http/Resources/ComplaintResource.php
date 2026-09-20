<?php

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'product_id' => $this->product_id,
            'assigned_to' => $this->assigned_to,
            'batch_no' => $this->batch_no,
            'type' => $this->type,
            'description' => $this->description,
            'resolution' => $this->resolution,
            'priority' => $this->priority,
            'status' => $this->status,
            'complaint_date' => $this->complaint_date->toDateString(),
            'resolved_date' => $this->resolved_date?->toDateString(),
        ];
    }
}

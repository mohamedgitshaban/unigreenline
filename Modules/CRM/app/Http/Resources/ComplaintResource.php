<?php

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\UserResource;
use Modules\Inventory\Http\Resources\ProductResource;

class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => CustomerResource::make($this->customer),
            'product' => ProductResource::make($this->product),
            'assigned_to' => UserResource::make($this->assignedTo),
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

<?php

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assigned_to' => $this->assigned_to,
            'name' => $this->name,
            'type' => $this->type,
            'contact' => $this->contact,
            'phone' => $this->phone,
            'email' => $this->email,
            'source' => $this->source,
            'status' => $this->status,
            'value' => $this->value,
            'notes' => $this->notes,
        ];
    }
}

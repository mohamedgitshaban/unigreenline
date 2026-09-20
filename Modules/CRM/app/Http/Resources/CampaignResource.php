<?php

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'name' => $this->name,
            'type' => $this->type,
            'target' => $this->target,
            'discount' => $this->discount,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'description' => $this->description,
            'status' => $this->status,
            'reach' => $this->reach,
            'revenue' => $this->revenue,
        ];
    }
}

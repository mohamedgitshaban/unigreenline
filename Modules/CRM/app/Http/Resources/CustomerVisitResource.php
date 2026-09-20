<?php

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerVisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'rep_id' => $this->rep_id,
            'visit_date' => $this->visit_date->toDateString(),
            'type' => $this->type,
            'outcome' => $this->outcome,
            'notes' => $this->notes,
            'next_visit' => $this->next_visit?->toDateString(),
        ];
    }
}

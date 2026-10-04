<?php

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\UserResource;

class CustomerVisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => CustomerResource::make($this->customer),
            'rep' => UserResource::make($this->rep),
            'visit_date' => $this->visit_date->toDateString(),
            'type' => $this->type,
            'outcome' => $this->outcome,
            'notes' => $this->notes,
            'next_visit' => $this->next_visit?->toDateString(),
        ];
    }
}

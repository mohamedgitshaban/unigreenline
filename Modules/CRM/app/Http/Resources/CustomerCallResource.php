<?php

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\UserResource;

class CustomerCallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => CustomerResource::make($this->customer),
            'rep' => UserResource::make($this->rep),
            'called_at' => $this->called_at->toIso8601String(),
            'direction' => $this->direction,
            'outcome' => $this->outcome,
            'duration_seconds' => $this->duration_seconds,
            'subject' => $this->subject,
            'message' => $this->message,
            'next_follow_up' => $this->next_follow_up?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

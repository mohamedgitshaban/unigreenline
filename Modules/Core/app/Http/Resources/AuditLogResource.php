<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at,
            'user' => UserResource::make($this->user),
            'user_name' => $this->user_name,
            'module' => $this->module,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'operation' => $this->operation,
            'prev_values' => $this->prev_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'prev_hash' => $this->prev_hash,
            'entry_hash' => $this->entry_hash,
        ];
    }
}

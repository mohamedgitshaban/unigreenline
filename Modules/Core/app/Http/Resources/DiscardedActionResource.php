<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscardedActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => UserResource::make($this->user),
            'user_name' => $this->user_name,
            'type' => $this->type,
            'label' => $this->label,
            'payload' => $this->payload,
            'created_at' => $this->created_at,
        ];
    }
}

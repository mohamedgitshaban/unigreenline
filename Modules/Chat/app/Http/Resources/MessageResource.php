<?php

namespace Modules\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'type' => $this->type,
            'body' => $this->body,
            // Null once the sender's account has been deleted.
            'sender' => $this->whenLoaded('sender', fn ($sender) => new ChatUserResource($sender)),
            'mine' => $this->sender_id === $request->user()->id,
            'attachments' => MessageAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at,
        ];
    }
}

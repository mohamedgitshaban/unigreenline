<?php

namespace Modules\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation as the signed-in user sees it: the person they are talking
 * to, and how many of that person's messages they have not read yet.
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $other = $this->resource->otherParticipantFor($request->user());

        return [
            'id' => $this->id,
            'participant' => $other === null ? null : new ChatUserResource($other),
            'last_message' => $this->whenLoaded('latestMessage', fn ($message) => new MessageResource($message)),
            'unread_count' => (int) ($this->unread_count ?? 0),
            'last_message_at' => $this->last_message_at,
            'created_at' => $this->created_at,
        ];
    }
}

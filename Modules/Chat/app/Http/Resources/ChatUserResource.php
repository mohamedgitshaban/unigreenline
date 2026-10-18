<?php

namespace Modules\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a colleague shows of themselves in a chat: a name and initials, not
 * the email, role or status the user admin screens expose.
 */
class ChatUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar' => $this->avatar_initials,
        ];
    }
}

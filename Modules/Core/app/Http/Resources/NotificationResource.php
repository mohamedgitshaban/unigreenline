<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'link_module' => $this->link_module,
            'link_entity' => $this->link_entity,
            'link_id' => $this->link_id,
            'link' => $this->link_url ? ['module' => $this->link_module, 'entity' => $this->link_entity, 'id' => $this->link_id, 'url' => $this->link_url] : null,
            'actor_id' => $this->actor_id,
            'unread' => $this->unread,
            'created_at' => $this->created_at,
        ];
    }
}

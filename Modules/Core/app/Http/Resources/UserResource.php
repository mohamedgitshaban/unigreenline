<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\DataScope;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->getRoleNames()->first(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'avatar' => $this->avatar_initials,
            'status' => $this->status,
            'manager_id' => $this->manager_id,
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? ['id' => $this->manager->id, 'name' => $this->manager->name] : null),
            'data_scope' => $this->data_scope,
            'effective_data_scope' => app(DataScope::class)->effective($this->resource),
            'last_login' => $this->last_login,
            'created_at' => $this->created_at,
            // Not an Eloquent relation (Core owning a relation to Inventory's
            // Warehouse would invert module dependency direction) — the
            // controller attaches this as a plain attribute for admin
            // show/store/update responses only.
            'warehouse_ids' => $this->when(
                array_key_exists('warehouse_ids', $this->resource->getAttributes()),
                fn () => $this->warehouse_ids
            ),
        ];
    }
}

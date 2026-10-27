<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A record reduced to what a dropdown needs: which one (`id`) and what to call it (`name`).
 */
class LookupResource extends JsonResource
{
    /**
     * @return array{id: string, name: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

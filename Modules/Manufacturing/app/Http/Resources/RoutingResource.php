<?php

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoutingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'notes' => $this->notes,
            'active' => $this->active,
            'operations' => $this->whenLoaded('operations', fn () => $this->operations->map(fn ($o) => [
                'id' => $o->id,
                'sequence' => $o->sequence,
                'name' => $o->name,
                'work_center' => $o->relationLoaded('workCenter') && $o->workCenter ? ['id' => $o->workCenter->id, 'code' => $o->workCenter->code, 'name' => $o->workCenter->name] : null,
                'work_center_id' => $o->work_center_id,
                'setup_minutes' => $o->setup_minutes,
                'run_minutes' => $o->run_minutes,
                'cleanup_minutes' => $o->cleanup_minutes,
                'depends_on_id' => $o->depends_on_id,
                'instructions' => $o->instructions,
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}

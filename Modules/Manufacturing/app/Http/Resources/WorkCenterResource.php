<?php

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkCenterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'efficiency_pct' => $this->efficiency_pct,
            'cost_per_hour' => $this->cost_per_hour,
            'labor_cost_per_hour' => $this->labor_cost_per_hour,
            'notes' => $this->notes,
            'active' => $this->active,
            'calendar' => $this->whenLoaded('calendar', fn () => $this->calendar->map(fn ($shift) => [
                'id' => $shift->id, 'weekday' => $shift->weekday, 'start_time' => substr($shift->start_time, 0, 5), 'end_time' => substr($shift->end_time, 0, 5),
            ])->values()),
            'downtimes' => $this->whenLoaded('downtimes', fn () => $this->downtimes->map(fn ($d) => [
                'id' => $d->id, 'starts_at' => $d->starts_at, 'ends_at' => $d->ends_at, 'reason' => $d->reason,
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}

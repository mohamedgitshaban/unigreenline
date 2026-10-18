<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'break_minutes' => $this->break_minutes,
            'grace_minutes' => $this->grace_minutes,
            'weekend_days' => $this->weekend_days,
            'scheduled_minutes' => $this->scheduledMinutes(),
            'is_default' => $this->is_default,
        ];
    }
}

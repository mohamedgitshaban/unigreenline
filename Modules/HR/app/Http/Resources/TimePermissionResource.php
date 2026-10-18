<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimePermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->employee ? ['id' => $this->employee->id, 'code' => $this->employee->code, 'name' => $this->employee->name] : null,
            'type' => $this->type,
            'permission_date' => $this->permission_date->toDateString(),
            'from_time' => substr($this->from_time, 0, 5),
            'to_time' => substr($this->to_time, 0, 5),
            'minutes' => $this->minutes,
            'reason' => $this->reason,
            'status' => $this->status,
            'decided_by' => $this->decidedBy ? ['id' => $this->decidedBy->id, 'name' => $this->decidedBy->name] : null,
            'decided_at' => $this->decided_at,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at,
        ];
    }
}

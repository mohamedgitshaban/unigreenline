<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->employee ? ['id' => $this->employee->id, 'code' => $this->employee->code, 'name' => $this->employee->name] : null,
            'work_date' => $this->work_date->toDateString(),
            'check_in' => $this->check_in ? substr($this->check_in, 0, 5) : null,
            'check_out' => $this->check_out ? substr($this->check_out, 0, 5) : null,
            'source' => $this->source,
            'notes' => $this->notes,
            'scheduled_minutes' => $this->scheduled_minutes,
            'worked_minutes' => $this->worked_minutes,
            'late_minutes' => $this->late_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,
            'overtime_minutes' => $this->overtime_minutes,
            // A day checked in but not yet out.
            'incomplete' => $this->check_in !== null && $this->check_out === null,
        ];
    }
}

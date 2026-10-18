<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkScheduleRequest extends FormRequest
{
    /**
     * The route's permission has already been checked; tenant ownership of the
     * record is checked by the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('hr_work_schedules')->where('tenant_id', $tenantId)->ignore($this->route('workSchedule'))],
            'start_time' => ['sometimes', 'date_format:H:i,H:i:s'],
            'end_time' => ['sometimes', 'date_format:H:i,H:i:s', 'after:start_time'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:480'],
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            // 0 = Sunday ... 6 = Saturday.
            'weekend_days' => ['sometimes', 'array', 'max:7'],
            'weekend_days.*' => ['integer', 'between:0,6', 'distinct'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}

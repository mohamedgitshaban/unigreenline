<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTimePermissionRequest extends FormRequest
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
            // Omit for a self-service user: it is always their own.
            'employee_id' => ['nullable', 'string', Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)],
            'type' => ['required', Rule::in(['late_arrival', 'early_leave', 'partial_absence'])],
            'permission_date' => ['required', 'date'],
            'from_time' => ['required', 'date_format:H:i,H:i:s'],
            'to_time' => ['required', 'date_format:H:i,H:i:s', 'after:from_time'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

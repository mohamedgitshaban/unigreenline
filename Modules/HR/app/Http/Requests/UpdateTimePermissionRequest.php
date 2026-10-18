<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTimePermissionRequest extends FormRequest
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
        return [
            'type' => ['sometimes', Rule::in(['late_arrival', 'early_leave', 'partial_absence'])],
            'permission_date' => ['sometimes', 'date'],
            'from_time' => ['sometimes', 'date_format:H:i,H:i:s'],
            'to_time' => ['sometimes', 'date_format:H:i,H:i:s'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

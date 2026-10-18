<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('hr_departments')->where('tenant_id', $tenantId)->ignore($this->route('department'))],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

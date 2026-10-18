<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('work_centers')->where('tenant_id', $tenantId)->ignore($this->route('workCenter'))],
            'name' => ['sometimes', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'efficiency_pct' => ['sometimes', 'numeric', 'gt:0', 'max:300'],
            'cost_per_hour' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'labor_cost_per_hour' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('hr_employees')->where('tenant_id', $tenantId)->ignore($this->route('employee'))],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:255'],
            // The login this employee uses to check in; one employee per login.
            'user_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId), Rule::unique('hr_employees', 'user_id')->ignore($this->route('employee'))],
            'department_id' => ['nullable', 'string', Rule::exists('hr_departments', 'id')->where('tenant_id', $tenantId)],
            'work_schedule_id' => ['nullable', 'string', Rule::exists('hr_work_schedules', 'id')->where('tenant_id', $tenantId)],
            'hire_date' => ['sometimes', 'date'],
            'termination_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'basic_salary' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'allowances' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'insurance_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'overtime_eligible' => ['sometimes', 'boolean'],
            'annual_leave_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ];
    }
}

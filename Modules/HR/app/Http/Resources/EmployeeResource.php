<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Pay figures are for people who can see payroll; everyone else sees the person, not the salary.
        $canSeePay = $request->user()?->can('payroll-runs.view') ?? false;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'national_id' => $this->national_id,
            'job_title' => $this->job_title,
            'user_id' => $this->user_id,
            'department' => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null,
            'work_schedule' => $this->workSchedule ? ['id' => $this->workSchedule->id, 'name' => $this->workSchedule->name] : null,
            'hire_date' => $this->hire_date->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),
            'status' => $this->status,
            'overtime_eligible' => $this->overtime_eligible,
            'annual_leave_days' => $this->annual_leave_days,
            'basic_salary' => $this->when($canSeePay, fn () => $this->basic_salary),
            'allowances' => $this->when($canSeePay, fn () => $this->allowances),
            'insurance_pct' => $this->when($canSeePay, fn () => $this->insurance_pct),
            'tax_pct' => $this->when($canSeePay, fn () => $this->tax_pct),
            'created_at' => $this->created_at,
        ];
    }
}

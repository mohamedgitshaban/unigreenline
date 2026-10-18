<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayslipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_run' => $this->payrollRun ? ['id' => $this->payrollRun->id, 'period' => $this->payrollRun->period, 'status' => $this->payrollRun->status] : null,
            'employee_id' => $this->employee_id,
            'employee_code' => $this->employee_code,
            'employee_name' => $this->employee_name,
            'period_days' => $this->period_days,
            'working_days' => $this->working_days,
            'weekend_days' => $this->weekend_days,
            'holiday_days' => $this->holiday_days,
            'present_days' => $this->present_days,
            'absent_days' => $this->absent_days,
            'paid_leave_days' => $this->paid_leave_days,
            'unpaid_leave_days' => $this->unpaid_leave_days,
            'incomplete_days' => $this->incomplete_days,
            'late_minutes' => $this->late_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,
            'shortfall_minutes' => $this->shortfall_minutes,
            'excused_minutes' => $this->excused_minutes,
            'unexcused_minutes' => $this->unexcused_minutes,
            'overtime_minutes' => $this->overtime_minutes,
            'basic_salary' => $this->basic_salary,
            'allowances' => $this->allowances,
            'overtime_pay' => $this->overtime_pay,
            'gross_earnings' => $this->gross_earnings,
            'absence_deduction' => $this->absence_deduction,
            'unpaid_leave_deduction' => $this->unpaid_leave_deduction,
            'shortfall_deduction' => $this->shortfall_deduction,
            'attendance_deductions' => $this->attendance_deductions,
            'earned_pay' => $this->earned_pay,
            'insurance' => $this->insurance,
            'tax' => $this->tax,
            'net_pay' => $this->net_pay,
        ];
    }
}

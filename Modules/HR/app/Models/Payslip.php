<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payroll_run_id', 'employee_id', 'employee_code', 'employee_name', 'period_days',
    'working_days', 'weekend_days', 'holiday_days', 'present_days', 'absent_days',
    'paid_leave_days', 'unpaid_leave_days', 'incomplete_days', 'late_minutes', 'early_leave_minutes',
    'shortfall_minutes', 'excused_minutes', 'unexcused_minutes', 'overtime_minutes', 'basic_salary',
    'allowances', 'overtime_pay', 'gross_earnings', 'absence_deduction', 'unpaid_leave_deduction',
    'shortfall_deduction', 'attendance_deductions', 'earned_pay', 'insurance', 'tax',
    'net_pay',
])]
class Payslip extends Model
{
    use HasUlids;

    protected $table = 'hr_payslips';

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'allowances' => 'decimal:2',
            'overtime_pay' => 'decimal:2',
            'gross_earnings' => 'decimal:2',
            'absence_deduction' => 'decimal:2',
            'unpaid_leave_deduction' => 'decimal:2',
            'shortfall_deduction' => 'decimal:2',
            'attendance_deductions' => 'decimal:2',
            'earned_pay' => 'decimal:2',
            'insurance' => 'decimal:2',
            'tax' => 'decimal:2',
            'net_pay' => 'decimal:2',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

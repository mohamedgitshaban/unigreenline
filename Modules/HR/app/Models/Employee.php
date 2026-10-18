<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\HR\Database\Factories\EmployeeFactory;

#[Fillable([
    'tenant_id', 'user_id', 'department_id', 'work_schedule_id', 'code', 'name', 'email', 'phone', 'national_id',
    'job_title', 'hire_date', 'termination_date', 'status', 'basic_salary', 'allowances', 'insurance_pct', 'tax_pct',
    'overtime_eligible', 'annual_leave_days',
])]
class Employee extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'hr_employees';

    protected $attributes = [
        'status' => 'active',
        'allowances' => 0,
        'insurance_pct' => 0,
        'tax_pct' => 0,
        'overtime_eligible' => false,
        'annual_leave_days' => 21,
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'termination_date' => 'date',
            'basic_salary' => 'decimal:2',
            'allowances' => 'decimal:2',
            'insurance_pct' => 'decimal:2',
            'tax_pct' => 'decimal:2',
            'overtime_eligible' => 'boolean',
            'annual_leave_days' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }
}

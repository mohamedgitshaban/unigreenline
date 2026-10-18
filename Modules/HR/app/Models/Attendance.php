<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\HR\Database\Factories\AttendanceFactory;

#[Fillable([
    'tenant_id', 'employee_id', 'work_date', 'check_in', 'check_out', 'source', 'notes',
    'scheduled_minutes', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes',
])]
class Attendance extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'hr_attendances';

    protected $attributes = [
        'source' => 'manual',
        'scheduled_minutes' => 0,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'overtime_minutes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'scheduled_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function newFactory(): AttendanceFactory
    {
        return AttendanceFactory::new();
    }
}

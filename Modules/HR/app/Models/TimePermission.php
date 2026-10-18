<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\HR\Database\Factories\TimePermissionFactory;

#[Fillable([
    'tenant_id', 'employee_id', 'type', 'permission_date', 'from_time', 'to_time', 'minutes', 'reason', 'status',
    'decided_by', 'decided_at', 'rejection_reason', 'created_by',
])]
class TimePermission extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'hr_time_permissions';

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'permission_date' => 'date',
            'minutes' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): TimePermissionFactory
    {
        return TimePermissionFactory::new();
    }
}

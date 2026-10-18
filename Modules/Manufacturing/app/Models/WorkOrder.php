<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Manufacturing\Database\Factories\WorkOrderFactory;

/**
 * One operation of a manufacturing order, on one work center. It becomes
 * `ready` once the work order it depends on is completed.
 */
#[Fillable([
    'tenant_id', 'production_order_id', 'routing_operation_id', 'number', 'sequence', 'name', 'work_center_id', 'depends_on_id',
    'status', 'qty_planned', 'qty_processed', 'qty_rejected', 'planned_minutes', 'actual_minutes',
    'scheduled_start', 'scheduled_end', 'actual_start', 'actual_end', 'assigned_to',
    'instructions', 'notes', 'failure_reason', 'labor_cost', 'machine_cost',
])]
class WorkOrder extends Model
{
    use HasFactory, HasUlids;

    /** Statuses that still occupy the work center. */
    public const OPEN = ['pending', 'ready', 'in_progress', 'paused'];

    protected $attributes = ['status' => 'pending', 'qty_processed' => 0, 'qty_rejected' => 0, 'planned_minutes' => 0, 'actual_minutes' => 0, 'labor_cost' => 0, 'machine_cost' => 0];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'qty_planned' => 'integer',
            'qty_processed' => 'integer',
            'qty_rejected' => 'integer',
            'planned_minutes' => 'integer',
            'actual_minutes' => 'integer',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'labor_cost' => 'decimal:2',
            'machine_cost' => 'decimal:2',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(RoutingOperation::class, 'routing_operation_id');
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(WorkOrderTimeLog::class)->orderBy('started_at');
    }

    public function qualityChecks(): HasMany
    {
        return $this->hasMany(QualityCheck::class);
    }

    protected static function newFactory(): WorkOrderFactory
    {
        return WorkOrderFactory::new();
    }
}

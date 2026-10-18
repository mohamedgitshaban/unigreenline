<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant;
use Modules\Manufacturing\Database\Factories\WorkCenterFactory;

/**
 * A machine, workstation or line that runs operations: how many at once, how
 * fast, what an hour of it costs, and when it is available.
 */
#[Fillable(['tenant_id', 'code', 'name', 'capacity', 'efficiency_pct', 'cost_per_hour', 'labor_cost_per_hour', 'notes', 'active'])]
class WorkCenter extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = ['capacity' => 1, 'efficiency_pct' => 100, 'cost_per_hour' => 0, 'labor_cost_per_hour' => 0, 'active' => true];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'efficiency_pct' => 'decimal:2',
            'cost_per_hour' => 'decimal:2',
            'labor_cost_per_hour' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function calendar(): HasMany
    {
        return $this->hasMany(WorkCenterCalendar::class)->orderBy('weekday')->orderBy('start_time');
    }

    public function downtimes(): HasMany
    {
        return $this->hasMany(WorkCenterDowntime::class)->orderBy('starts_at');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    protected static function newFactory(): WorkCenterFactory
    {
        return WorkCenterFactory::new();
    }
}

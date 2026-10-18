<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a routing: where it runs, setup / run / cleanup time and what it depends on.
 */
#[Fillable(['routing_id', 'sequence', 'name', 'work_center_id', 'setup_minutes', 'run_minutes', 'cleanup_minutes', 'depends_on_id', 'instructions'])]
class RoutingOperation extends Model
{
    use HasUlids;

    protected $attributes = ['setup_minutes' => 0, 'run_minutes' => 0, 'cleanup_minutes' => 0];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'setup_minutes' => 'integer',
            'run_minutes' => 'decimal:2',
            'cleanup_minutes' => 'integer',
        ];
    }

    /**
     * Planned minutes to put `$cartons` of product through the operation on its work center,
     * slowed by the work center's efficiency.
     */
    public function plannedMinutes(int $cartons, float $efficiencyPct = 100.0): int
    {
        $run = (float) $this->run_minutes * $cartons;
        $run = $efficiencyPct > 0 ? $run * 100 / $efficiencyPct : $run;

        return (int) ceil($this->setup_minutes + $run + $this->cleanup_minutes);
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class);
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_id');
    }
}

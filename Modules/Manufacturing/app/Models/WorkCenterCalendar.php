<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shift of a work center's week (0 = Sunday ... 6 = Saturday).
 */
#[Fillable(['work_center_id', 'weekday', 'start_time', 'end_time'])]
class WorkCenterCalendar extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }
}

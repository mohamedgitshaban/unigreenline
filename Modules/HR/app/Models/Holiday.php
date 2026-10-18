<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\HR\Database\Factories\HolidayFactory;

/**
 * A company-wide holiday (one day or a range). Paid for everyone, whatever their schedule.
 */
#[Fillable(['tenant_id', 'name', 'start_date', 'end_date'])]
class Holiday extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'hr_holidays';

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): HolidayFactory
    {
        return HolidayFactory::new();
    }
}

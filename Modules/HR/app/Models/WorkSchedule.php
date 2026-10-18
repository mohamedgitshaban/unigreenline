<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\HR\Database\Factories\WorkScheduleFactory;

/**
 * When an employee is expected to work: a daily window (minus a break), a grace
 * allowance, and which weekdays are the weekend. Times are stored as `H:i:s`.
 */
#[Fillable(['tenant_id', 'name', 'start_time', 'end_time', 'break_minutes', 'grace_minutes', 'weekend_days', 'is_default'])]
class WorkSchedule extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'hr_work_schedules';

    protected $attributes = ['break_minutes' => 0, 'grace_minutes' => 0, 'is_default' => false];

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'grace_minutes' => 'integer',
            'weekend_days' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Minutes an employee is expected to be at work in a normal day.
     */
    public function scheduledMinutes(): int
    {
        return max(0, $this->minutesOf($this->end_time) - $this->minutesOf($this->start_time) - $this->break_minutes);
    }

    public function isWeekend(\DateTimeInterface $date): bool
    {
        return in_array((int) $date->format('w'), $this->weekend_days ?? [], true);
    }

    public function minutesOf(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): WorkScheduleFactory
    {
        return WorkScheduleFactory::new();
    }
}

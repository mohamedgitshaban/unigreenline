<?php

namespace Modules\HR\Support;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Modules\HR\Models\Holiday;

/**
 * The company's holidays over a stretch of time, so a day can be asked "is this
 * a holiday?" without a query per day.
 */
class WorkCalendar
{
    /**
     * @param  Collection<int, Holiday>  $holidays
     */
    public function __construct(private readonly Collection $holidays) {}

    public static function between(string $tenantId, DateTimeInterface $from, DateTimeInterface $to): self
    {
        return new self(
            Holiday::query()
                ->where('tenant_id', $tenantId)
                ->whereDate('start_date', '<=', $to->format('Y-m-d'))
                ->whereDate('end_date', '>=', $from->format('Y-m-d'))
                ->get()
        );
    }

    public function isHoliday(DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->holidays->contains(fn (Holiday $h) => $h->start_date->format('Y-m-d') <= $day && $h->end_date->format('Y-m-d') >= $day);
    }
}

<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\HR\Support\WorkCalendar;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Models\WorkCenter;
use Modules\Manufacturing\Models\WorkOrder;

/**
 * Finite-capacity scheduling of an operation on a work center.
 *
 * Working time comes from the center's weekly shifts, less company holidays and
 * declared downtime; an operation takes `minutes` of working time and may run across
 * shifts and days. The result is then checked against the center's capacity (how many
 * operations may overlap): if it is already full at that time the operation moves to
 * the earliest moment a place frees up.
 *
 * Booking safety: callers must hold the work center row locked (`lockedCenter()`) for the
 * length of their transaction. Every scheduler serialises on that lock, and the bookings
 * are read with a locking read so a booking committed a moment ago is never missed —
 * two requests can never both take the last free place.
 */
class WorkCenterScheduler
{
    /**
     * Locks and returns the work center for the rest of the transaction.
     */
    public function lockedCenter(string $workCenterId): WorkCenter
    {
        return WorkCenter::query()->whereKey($workCenterId)->lockForUpdate()->with(['calendar', 'downtimes'])->firstOrFail();
    }

    /**
     * The earliest slot of `$minutes` working minutes at or after `$earliest` with a free place.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function find(WorkCenter $center, int $minutes, Carbon $earliest, ?string $ignoreWorkOrderId = null): array
    {
        $horizonEnd = $earliest->copy()->addDays(config('manufacturing.scheduling_horizon_days'));
        $segments = $this->segments($center, $earliest, $horizonEnd);
        $cursor = $earliest->copy();

        for ($attempt = 0; $attempt < 500; $attempt++) {
            [$start, $end] = $this->place($segments, $cursor, max(1, $minutes));

            if ($start === null) {
                break;
            }

            $overlapping = $this->overlapping($center, $start, $end, $ignoreWorkOrderId);

            if ($overlapping->count() < $center->capacity) {
                return [$start, $end];
            }

            // Full: try again once the earliest of the blocking operations has finished.
            $cursor = $overlapping->min('scheduled_end')->copy();
        }

        throw new ManufacturingRuleException("No free slot on work center {$center->name} within the next ".config('manufacturing.scheduling_horizon_days').' days.');
    }

    /**
     * Operations booked on the center in a period that would exceed its capacity if one more were added.
     * Empty means the period is free.
     *
     * @return Collection<int, WorkOrder>
     */
    public function conflicts(WorkCenter $center, Carbon $start, Carbon $end, ?string $ignoreWorkOrderId = null): Collection
    {
        $overlapping = $this->overlapping($center, $start, $end, $ignoreWorkOrderId);

        return $overlapping->count() >= $center->capacity ? $overlapping : collect();
    }

    /**
     * End of `$minutes` of working time starting exactly at `$start`, or null when `$start` is not working time.
     */
    public function endFrom(WorkCenter $center, Carbon $start, int $minutes): ?Carbon
    {
        $segments = $this->segments($center, $start, $start->copy()->addDays(config('manufacturing.scheduling_horizon_days')));

        foreach ($segments as [$from, $to]) {
            if ($start->gte($from) && $start->lt($to)) {
                [, $end] = $this->place($segments, $start, max(1, $minutes));

                return $end;
            }
        }

        return null;
    }

    /**
     * Working minutes available on the center in a period (shifts less downtime and holidays).
     */
    public function availableMinutes(WorkCenter $center, Carbon $from, Carbon $to): int
    {
        $total = 0;

        foreach ($this->segments($center->loadMissing(['calendar', 'downtimes']), $from, $to) as [$start, $end]) {
            $total += (int) round($start->diffInSeconds($end, true) / 60);
        }

        return $total;
    }

    /**
     * Open work orders on the center whose booked time overlaps `[start, end)`. Locking read, see class docs.
     *
     * @return Collection<int, WorkOrder>
     */
    private function overlapping(WorkCenter $center, Carbon $start, Carbon $end, ?string $ignoreWorkOrderId): Collection
    {
        return WorkOrder::query()
            ->where('work_center_id', $center->id)
            ->whereIn('status', WorkOrder::OPEN)
            ->whereNotNull('scheduled_start')
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start)
            ->when($ignoreWorkOrderId, fn ($query) => $query->whereKeyNot($ignoreWorkOrderId))
            ->lockForUpdate()
            ->get();
    }

    /**
     * Lays `$minutes` of working time over the segments from `$cursor` on.
     *
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $segments
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function place(array $segments, Carbon $cursor, int $minutes): array
    {
        $start = null;
        $remaining = $minutes * 60;

        foreach ($segments as [$from, $to]) {
            if ($to->lte($cursor)) {
                continue;
            }

            $begin = $from->gt($cursor) ? $from->copy() : $cursor->copy();
            $start ??= $begin->copy();
            $room = (int) $begin->diffInSeconds($to, true);

            if ($room >= $remaining) {
                return [$start, $begin->copy()->addSeconds($remaining)];
            }

            $remaining -= $room;
        }

        return [null, null];
    }

    /**
     * Working segments between two moments, in order.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function segments(WorkCenter $center, Carbon $from, Carbon $to): array
    {
        $shifts = $center->calendar->groupBy('weekday');

        if ($shifts->isEmpty()) {
            $default = config('manufacturing.default_shift');
            $shift = (object) ['start_time' => $default['start'], 'end_time' => $default['end']];
            $shifts = collect($default['weekdays'])->mapWithKeys(fn ($weekday) => [$weekday => collect([$shift])]);
        }

        $holidays = WorkCalendar::between($center->tenant_id, $from, $to);
        $downtimes = $center->downtimes->filter(fn ($d) => $d->ends_at->gt($from) && $d->starts_at->lt($to))->sortBy('starts_at')->values();
        $segments = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            if ($holidays->isHoliday($day)) {
                continue;
            }

            foreach ($shifts->get($day->dayOfWeek, collect()) as $shift) {
                $parts = [[$day->copy()->setTimeFromTimeString($shift->start_time), $day->copy()->setTimeFromTimeString($shift->end_time)]];

                foreach ($downtimes as $downtime) {
                    $parts = $this->subtract($parts, $downtime->starts_at, $downtime->ends_at);
                }

                foreach ($parts as $part) {
                    $segments[] = $part;
                }
            }
        }

        usort($segments, fn ($a, $b) => $a[0] <=> $b[0]);

        return $segments;
    }

    /**
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $parts
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function subtract(array $parts, Carbon $cutStart, Carbon $cutEnd): array
    {
        $result = [];

        foreach ($parts as [$start, $end]) {
            if ($cutEnd->lte($start) || $cutStart->gte($end)) {
                $result[] = [$start, $end];

                continue;
            }

            if ($cutStart->gt($start)) {
                $result[] = [$start, $cutStart->copy()];
            }

            if ($cutEnd->lt($end)) {
                $result[] = [$cutEnd->copy(), $end];
            }
        }

        return $result;
    }
}

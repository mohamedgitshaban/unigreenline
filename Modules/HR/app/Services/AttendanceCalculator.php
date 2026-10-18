<?php

namespace Modules\HR\Services;

use Modules\HR\Models\WorkSchedule;

/**
 * Turns one day's check-in / check-out into the figures payroll needs, measured
 * against the employee's schedule:
 *  - late: minutes after the scheduled start;
 *  - early leave: minutes before the scheduled end;
 *  - overtime: minutes after the scheduled end;
 *  - worked: time between check-in and check-out less the break (null until
 *    the employee has checked out).
 */
class AttendanceCalculator
{
    /**
     * @return array{scheduled_minutes: int, worked_minutes: ?int, late_minutes: int, early_leave_minutes: int, overtime_minutes: int}
     */
    public function compute(WorkSchedule $schedule, ?string $checkIn, ?string $checkOut): array
    {
        $start = $schedule->minutesOf($schedule->start_time);
        $end = $schedule->minutesOf($schedule->end_time);

        $in = $checkIn === null ? null : $schedule->minutesOf($checkIn);
        $out = $checkOut === null ? null : $schedule->minutesOf($checkOut);

        $result = [
            'scheduled_minutes' => $schedule->scheduledMinutes(),
            'worked_minutes' => null,
            'late_minutes' => $in === null ? 0 : max(0, $in - $start),
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
        ];

        if ($in === null || $out === null) {
            return $result;
        }

        $span = max(0, $out - $in);

        $result['worked_minutes'] = $span > $schedule->break_minutes ? $span - $schedule->break_minutes : $span;
        $result['early_leave_minutes'] = max(0, $end - $out);
        $result['overtime_minutes'] = max(0, $out - $end);

        return $result;
    }
}

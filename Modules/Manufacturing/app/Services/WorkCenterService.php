<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Models\WorkCenter;

class WorkCenterService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WorkCenter
    {
        $calendar = $data['calendar'] ?? [];
        unset($data['calendar']);

        return DB::transaction(function () use ($data, $calendar) {
            $center = WorkCenter::create($data);
            $center->calendar()->createMany($calendar);

            return $center->load('calendar');
        });
    }

    /**
     * Replaces the weekly shifts.
     *
     * @param  array<int, array{weekday: int, start_time: string, end_time: string}>  $shifts
     */
    public function replaceCalendar(WorkCenter $center, array $shifts): WorkCenter
    {
        return DB::transaction(function () use ($center, $shifts) {
            $center->calendar()->delete();
            $center->calendar()->createMany($shifts);

            return $center->load('calendar');
        });
    }
}

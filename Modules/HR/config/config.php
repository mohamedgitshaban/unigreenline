<?php

return [
    'name' => 'HR',

    // A month is paid as a fixed salary; one day is 1/30 of it whatever the month's length.
    'days_per_month' => 30,

    // Overtime is paid at the hourly rate times this (only for employees marked overtime-eligible).
    'overtime_multiplier' => 1.5,

    // Time missed and not excused (late, early leave, partial days) costs the hourly rate times this.
    'shortfall_multiplier' => 1.0,

    // Used for an employee who has no schedule, when the company has no default one either.
    'default_schedule' => [
        'start' => '09:00',
        'end' => '17:00',
        'break_minutes' => 0,
        'grace_minutes' => 0,
        // 0 = Sunday ... 6 = Saturday. Friday and Saturday is the Egyptian weekend.
        'weekend_days' => [5, 6],
    ],
];

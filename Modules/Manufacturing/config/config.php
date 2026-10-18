<?php

return [
    'name' => 'Manufacturing',

    // Used by a work center that has no calendar of its own: one shift on these weekdays
    // (0 = Sunday ... 6 = Saturday; the company week is Sunday to Thursday).
    'default_shift' => [
        'start' => '08:00',
        'end' => '16:00',
        'weekdays' => [0, 1, 2, 3, 4],
    ],

    // How far ahead the scheduler looks for a free slot before it gives up.
    'scheduling_horizon_days' => 365,
];

<?php

namespace Modules\HR\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\HR\Models\WorkSchedule;

/**
 * @extends Factory<WorkSchedule>
 */
class WorkScheduleFactory extends Factory
{
    protected $model = WorkSchedule::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'break_minutes' => 0,
            'grace_minutes' => 0,
            'weekend_days' => [5, 6],
            'is_default' => false,
        ];
    }
}

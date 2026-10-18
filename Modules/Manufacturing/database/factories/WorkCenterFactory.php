<?php

namespace Modules\Manufacturing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Manufacturing\Models\WorkCenter;

/**
 * @extends Factory<WorkCenter>
 */
class WorkCenterFactory extends Factory
{
    protected $model = WorkCenter::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'WC'.fake()->unique()->numerify('####'),
            'name' => fake()->unique()->words(2, true),
            'capacity' => 1,
            'efficiency_pct' => 100,
            'cost_per_hour' => 60,
            'labor_cost_per_hour' => 30,
            'active' => true,
        ];
    }

    /**
     * Monday to Friday... as the company week runs: Sunday to Thursday, 08:00-16:00.
     */
    public function withWeeklyShift(string $start = '08:00', string $end = '16:00', array $weekdays = [0, 1, 2, 3, 4]): static
    {
        return $this->afterCreating(function (WorkCenter $center) use ($start, $end, $weekdays) {
            foreach ($weekdays as $weekday) {
                $center->calendar()->create(['weekday' => $weekday, 'start_time' => $start, 'end_time' => $end]);
            }
        });
    }
}

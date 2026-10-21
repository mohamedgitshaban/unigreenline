<?php

namespace Modules\HR\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\HR\Models\JobPosition;

/**
 * @extends Factory<JobPosition>
 */
class JobPositionFactory extends Factory
{
    protected $model = JobPosition::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->jobTitle(),
            'vacancies' => 1,
            'status' => 'open',
            'employment_type' => 'full_time',
        ];
    }
}

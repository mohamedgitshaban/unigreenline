<?php

namespace Modules\HR\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\HR\Models\Applicant;
use Modules\HR\Models\RecruitmentStage;

/**
 * @extends Factory<Applicant>
 */
class ApplicantFactory extends Factory
{
    protected $model = Applicant::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'stage_id' => RecruitmentStage::factory(),
            'status' => 'active',
            'applied_on' => now()->toDateString(),
            'stage_changed_at' => now(),
        ];
    }
}

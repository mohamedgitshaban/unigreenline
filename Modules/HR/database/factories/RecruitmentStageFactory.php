<?php

namespace Modules\HR\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\HR\Models\RecruitmentStage;

/**
 * @extends Factory<RecruitmentStage>
 */
class RecruitmentStageFactory extends Factory
{
    protected $model = RecruitmentStage::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true),
            'sequence' => 10,
            'is_hired' => false,
        ];
    }
}

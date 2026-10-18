<?php

namespace Modules\Manufacturing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Manufacturing\Models\QualityCheckDefinition;

/**
 * @extends Factory<QualityCheckDefinition>
 */
class QualityCheckDefinitionFactory extends Factory
{
    protected $model = QualityCheckDefinition::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(3, true),
            'point' => 'final',
            'type' => 'pass_fail',
            'mandatory' => true,
            'active' => true,
        ];
    }
}

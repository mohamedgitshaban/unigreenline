<?php

namespace Modules\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\AssetCategory;
use Modules\Core\Models\Tenant;

/**
 * @extends Factory<AssetCategory>
 */
class AssetCategoryFactory extends Factory
{
    protected $model = AssetCategory::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true),
            'asset_account_code' => '1510',
            'accumulated_account_code' => '1590',
            'expense_account_code' => '6500',
            'method' => 'straight_line',
            'duration_periods' => 60,
            'period_months' => 1,
            'declining_factor' => 0.3,
            'prorata' => false,
            'salvage_pct' => 0,
            'auto_post' => true,
            'active' => true,
        ];
    }
}

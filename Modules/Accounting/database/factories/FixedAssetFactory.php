<?php

namespace Modules\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\AssetCategory;
use Modules\Accounting\Models\FixedAsset;
use Modules\Core\Models\Tenant;

/**
 * @extends Factory<FixedAsset>
 */
class FixedAssetFactory extends Factory
{
    protected $model = FixedAsset::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'number' => fake()->unique()->numerify('FA#####'),
            'name' => fake()->words(3, true),
            'category_id' => AssetCategory::factory(),
            'status' => 'draft',
            'acquisition_date' => '2026-01-01',
            'depreciation_start_date' => '2026-01-01',
            'original_value' => 12000,
            'salvage_value' => 0,
            'method' => 'straight_line',
            'duration_periods' => 12,
            'period_months' => 1,
            'declining_factor' => 0.3,
            'prorata' => false,
            'auto_post' => true,
            'asset_account_code' => '1510',
            'accumulated_account_code' => '1590',
            'expense_account_code' => '6500',
        ];
    }
}

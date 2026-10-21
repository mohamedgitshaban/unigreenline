<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Database\Factories\AssetCategoryFactory;

/**
 * A kind of fixed asset (an "asset model"): the accounts it is booked to and how it is depreciated by default.
 */
#[Fillable([
    'tenant_id', 'name', 'asset_account_code', 'accumulated_account_code', 'expense_account_code', 'method', 'duration_periods',
    'period_months', 'declining_factor', 'prorata', 'salvage_pct', 'auto_post', 'active', 'description',
])]
class AssetCategory extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = [
        'asset_account_code' => '1510', 'accumulated_account_code' => '1590', 'expense_account_code' => '6500',
        'method' => 'straight_line', 'duration_periods' => 60, 'period_months' => 1, 'declining_factor' => 0.3,
        'prorata' => false, 'salvage_pct' => 0, 'auto_post' => true, 'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'duration_periods' => 'integer',
            'period_months' => 'integer',
            'declining_factor' => 'decimal:4',
            'salvage_pct' => 'decimal:2',
            'prorata' => 'boolean',
            'auto_post' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category_id');
    }

    protected static function newFactory(): AssetCategoryFactory
    {
        return AssetCategoryFactory::new();
    }
}

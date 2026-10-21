<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Database\Factories\FixedAssetFactory;
use Modules\Core\Models\User;

/**
 * Something the company owns and writes off over time. `accumulated_depreciation` is what has been posted so far;
 * the book value is the original value less that.
 */
#[Fillable([
    'tenant_id', 'number', 'name', 'category_id', 'status', 'acquisition_date', 'depreciation_start_date', 'original_value',
    'salvage_value', 'accumulated_depreciation', 'method', 'duration_periods', 'period_months', 'declining_factor', 'prorata',
    'auto_post', 'asset_account_code', 'accumulated_account_code', 'expense_account_code', 'supplier_id', 'reference',
    'serial_number', 'location', 'assigned_user_id', 'notes', 'acquisition_journal_entry_id', 'activated_at', 'paused_at',
    'disposal_date', 'disposal_value', 'disposal_reason', 'disposal_journal_entry_id', 'created_by',
])]
class FixedAsset extends Model
{
    use HasFactory, HasUlids;

    /** Statuses in which the asset is still on the books and can be depreciated or disposed of. */
    public const LIVE = ['running', 'paused', 'fully_depreciated'];

    protected $attributes = ['status' => 'draft', 'accumulated_depreciation' => 0, 'salvage_value' => 0];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'depreciation_start_date' => 'date',
            'original_value' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'declining_factor' => 'decimal:4',
            'duration_periods' => 'integer',
            'period_months' => 'integer',
            'prorata' => 'boolean',
            'auto_post' => 'boolean',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
            'disposal_date' => 'date',
            'disposal_value' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class)->orderBy('sequence');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookValue(): float
    {
        return round((float) $this->original_value - (float) $this->accumulated_depreciation, 2);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    protected static function newFactory(): FixedAssetFactory
    {
        return FixedAssetFactory::new();
    }
}

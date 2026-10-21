<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an asset's depreciation board: planned until the journal entry is posted.
 */
#[Fillable([
    'tenant_id', 'fixed_asset_id', 'sequence', 'period_start', 'depreciation_date', 'amount', 'accumulated_after',
    'book_value_after', 'status', 'journal_entry_id', 'posted_at', 'posted_by',
])]
class FixedAssetDepreciation extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'period_start' => 'date',
            'depreciation_date' => 'date',
            'amount' => 'decimal:2',
            'accumulated_after' => 'decimal:2',
            'book_value_after' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}

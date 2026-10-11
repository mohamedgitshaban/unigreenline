<?php

namespace Modules\Recap\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;
use Modules\Recap\Database\Factories\RecapFactory;

/**
 * A stock write-off document (expired, damaged, lost…). Draft until
 * approved; approving removes the cartons from their batches and posts
 * Dr Inventory Write-off Expense (6300) / Cr Inventory (1300) for their cost.
 */
#[Fillable([
    'tenant_id', 'warehouse_id', 'reason', 'status', 'recap_date', 'notes', 'total_value',
    'journal_entry_id', 'created_by', 'approved_by', 'approved_at', 'rejection_reason',
])]
class Recap extends Model
{
    use HasFactory, HasUlids;

    public const REASONS = ['expired', 'damaged', 'lost', 'recall', 'other'];

    protected $attributes = [
        'reason' => 'expired',
        'status' => 'draft',
        'total_value' => 0,
    ];

    protected function casts(): array
    {
        return [
            'recap_date' => 'date',
            'total_value' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecapLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    protected static function newFactory(): RecapFactory
    {
        return RecapFactory::new();
    }
}

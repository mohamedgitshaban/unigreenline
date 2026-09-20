<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Database\Factories\AccountFactory;
use Modules\Core\Models\Tenant;

#[Fillable(['tenant_id', 'code', 'name', 'type', 'level', 'parent_id', 'active'])]
class Account extends Model
{
    use HasFactory, HasUlids;

    /** Account types whose balance increases on the debit side. */
    private const DEBIT_NORMAL_TYPES = ['Asset', 'Expense'];

    protected $table = 'chart_of_accounts';

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'balance' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Applies a debit/credit pair to this account's running balance,
     * respecting which side is that account type's normal balance
     * (debit increases Asset/Expense; credit increases Liability/Equity/Revenue).
     */
    public function applyPosting(float $debit, float $credit): void
    {
        $delta = in_array($this->type, self::DEBIT_NORMAL_TYPES, true)
            ? $debit - $credit
            : $credit - $debit;

        $this->increment('balance', $delta);
    }

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }
}

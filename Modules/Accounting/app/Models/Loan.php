<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Database\Factories\LoanFactory;
use Modules\Core\Models\User;

/**
 * Money the company borrowed (`borrowed`) or lent (`granted`), with its repayment schedule.
 */
#[Fillable([
    'tenant_id', 'number', 'name', 'kind', 'counterparty_type', 'counterparty_id', 'counterparty_name', 'principal',
    'annual_rate_pct', 'method', 'periods', 'period_months', 'grace_periods', 'start_date', 'first_due_date',
    'disbursement_account_code', 'principal_account_code', 'interest_account_code', 'status', 'outstanding_principal',
    'interest_paid', 'activated_at', 'closed_at', 'disbursement_journal_entry_id', 'notes', 'last_reminded_on', 'created_by',
])]
class Loan extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = ['status' => 'draft', 'outstanding_principal' => 0, 'interest_paid' => 0, 'grace_periods' => 0, 'period_months' => 1];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'annual_rate_pct' => 'decimal:4',
            'periods' => 'integer',
            'period_months' => 'integer',
            'grace_periods' => 'integer',
            'start_date' => 'date',
            'first_due_date' => 'date',
            'outstanding_principal' => 'decimal:2',
            'interest_paid' => 'decimal:2',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_reminded_on' => 'date',
        ];
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    public function paidInstallments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->where('status', 'paid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): LoanFactory
    {
        return LoanFactory::new();
    }
}

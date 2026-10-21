<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a loan's repayment schedule.
 */
#[Fillable([
    'tenant_id', 'loan_id', 'sequence', 'due_date', 'opening_balance', 'principal_amount', 'interest_amount', 'payment_amount',
    'closing_balance', 'status', 'paid_on', 'payment_account_code', 'journal_entry_id', 'paid_by',
])]
class LoanInstallment extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_date' => 'date',
            'opening_balance' => 'decimal:2',
            'principal_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'payment_amount' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}

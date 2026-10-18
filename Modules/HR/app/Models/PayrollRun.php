<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\HR\Database\Factories\PayrollRunFactory;

/**
 * One month's payroll. Draft while it can still be recalculated, approved once
 * the salaries expense is posted to the ledger, paid once the money has gone out.
 */
#[Fillable([
    'tenant_id', 'period', 'period_start', 'period_end', 'status', 'employees_count', 'total_earned', 'total_withheld',
    'total_net', 'journal_entry_id', 'payment_journal_entry_id', 'payment_method', 'paid_at', 'approved_by',
    'approved_at', 'created_by', 'notes',
])]
class PayrollRun extends Model
{
    use HasFactory, HasUlids;

    /** Chart-of-accounts code credited when the net pay is handed over, per payment method. */
    public const PAYMENT_ACCOUNT_CODES = ['cash' => '1110', 'bank' => '1120'];

    protected $table = 'hr_payroll_runs';

    protected $attributes = ['status' => 'draft', 'employees_count' => 0, 'total_earned' => 0, 'total_withheld' => 0, 'total_net' => 0];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'employees_count' => 'integer',
            'total_earned' => 'decimal:2',
            'total_withheld' => 'decimal:2',
            'total_net' => 'decimal:2',
            'paid_at' => 'datetime',
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

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function paymentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected static function newFactory(): PayrollRunFactory
    {
        return PayrollRunFactory::new();
    }
}

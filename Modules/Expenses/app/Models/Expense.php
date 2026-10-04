<?php

namespace Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Expenses\Database\Factories\ExpenseFactory;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;

#[Fillable([
    'tenant_id', 'category_id', 'warehouse_id', 'supplier_id', 'payee', 'created_by', 'approved_by',
    'journal_entry_id', 'status', 'payment_method', 'expense_date', 'amount', 'reference',
    'description', 'receipt_path', 'approved_at', 'rejection_reason',
])]
class Expense extends Model
{
    use HasFactory, HasUlids;

    /**
     * Chart-of-accounts code credited on approval, per payment method.
     *
     * @var array<string, string>
     */
    public const PAYMENT_ACCOUNT_CODES = ['cash' => '1110', 'bank' => '1120'];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Where uploaded receipts for a tenant are stored, on the default disk.
     */
    public static function receiptDirectory(string $tenantId): string
    {
        return "expense-receipts/{$tenantId}";
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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

    protected static function newFactory(): ExpenseFactory
    {
        return ExpenseFactory::new();
    }
}

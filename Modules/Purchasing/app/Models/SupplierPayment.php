<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Purchasing\Database\Factories\SupplierPaymentFactory;

/**
 * Money paid to a supplier against what we owe them. Posts Dr Accounts Payable
 * / Cr Cash or Bank and moves the supplier's balance toward zero. Payments are
 * never edited or deleted — a wrong one is corrected with a manual journal entry.
 */
#[Fillable(['tenant_id', 'supplier_id', 'purchase_order_id', 'amount', 'method', 'payment_date', 'reference', 'notes', 'created_by', 'journal_entry_id'])]
class SupplierPayment extends Model
{
    use HasFactory, HasUlids;

    /** Chart-of-accounts code credited, per payment method. */
    public const PAYMENT_ACCOUNT_CODES = ['cash' => '1110', 'bank' => '1120'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    protected static function newFactory(): SupplierPaymentFactory
    {
        return SupplierPaymentFactory::new();
    }
}

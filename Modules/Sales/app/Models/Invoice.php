<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\Sales\Database\Factories\InvoiceFactory;

/**
 * Never hard-deleted (spec §4.8) — void instead. delete() throws as an
 * app-level guard; the DB-level grant revocation described in the spec is a
 * deployment concern, not something a migration can express.
 */
#[Fillable(['tenant_id', 'so_id', 'customer_id', 'issued_date', 'due_date', 'subtotal', 'tax_amount', 'total', 'paid', 'balance', 'status'])]
class Invoice extends Model
{
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'issued_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Invoices are never deleted — void them instead.');
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}

<?php

namespace Modules\CRM\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Database\Factories\CustomerFactory;

#[Fillable([
    'tenant_id', 'sales_rep_id', 'name', 'type', 'classification', 'phone', 'email',
    'governorate', 'province', 'city', 'area', 'address', 'credit_limit', 'pay_terms',
    'status',
])]
class Customer extends Model
{
    use HasFactory, HasUlids;

    /**
     * Mirrors this table's DB-level defaults so a freshly created()
     * instance reflects them immediately, without a reload — controllers
     * that don't pass these fields would otherwise serialize them as null
     * in the response even though the DB row has the real default.
     */
    protected $attributes = [
        'classification' => 'B',
        'credit_limit' => 50000,
        'balance' => 0,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    /**
     * Orders and invoices live in the Sales module, which already depends
     * on CRM for Customer — a relation here in the other direction would
     * make the two modules depend on each other. The controller composes
     * those manually for the nested show response instead.
     */
    public function visits(): HasMany
    {
        return $this->hasMany(CustomerVisit::class);
    }

    /**
     * How much of the credit limit is already used once this amount is
     * added, as a fraction (0.85 = 85%). Used for the credit-warning
     * threshold in sales order creation (spec §5.6).
     */
    public function utilizationAfter(float $additionalAmount): float
    {
        if ((float) $this->credit_limit <= 0) {
            return 1.0;
        }

        return ((float) $this->balance + $additionalAmount) / (float) $this->credit_limit;
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}

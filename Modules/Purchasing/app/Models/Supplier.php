<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant;
use Modules\Purchasing\Database\Factories\SupplierFactory;

#[Fillable(['tenant_id', 'name', 'country', 'city', 'contact', 'email', 'phone', 'pay_terms', 'currency', 'rating', 'status'])]
class Supplier extends Model
{
    use HasFactory, HasUlids;

    /** Mirrors this table's DB-level defaults — see CRM's Customer model for why. */
    protected $attributes = [
        'pay_terms' => 'Net 30',
        'currency' => 'EGP',
        'balance' => 0,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'rating' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }
}

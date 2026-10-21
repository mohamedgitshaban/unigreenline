<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Database\Factories\SalesOrderFactory;

#[Fillable([
    'tenant_id', 'customer_id', 'sales_rep_id', 'warehouse_id', 'status', 'pay_type',
    'grace_period', 'due_date', 'invoice_discount', 'subtotal', 'tax_amount', 'total',
    'stock_deducted', 'notes', 'order_date',
])]
class SalesOrder extends Model
{
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'grace_period' => 'integer',
            'due_date' => 'date',
            'invoice_discount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'stock_deducted' => 'boolean',
            'order_date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'so_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'so_id');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class, 'so_id');
    }

    /**
     * Ownership scoping (spec §2): a user whose data scope is `team` (a Sales Rep by default) sees only
     * the orders of themselves and of everyone who reports to them; any other user holding
     * sales.view permission sees every order. See {@see DataScope}.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): Builder
    {
        return app(DataScope::class)->constrain($query, $user, 'sales_rep_id');
    }

    protected static function newFactory(): SalesOrderFactory
    {
        return SalesOrderFactory::new();
    }
}

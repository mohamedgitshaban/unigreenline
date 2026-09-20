<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Database\Factories\WarehouseFactory;

#[Fillable([
    'tenant_id', 'name', 'city', 'governorate', 'address', 'manager_id', 'manager_name',
    'temperature', 'capacity', 'phone', 'status',
])]
class Warehouse extends Model
{
    use HasFactory, HasUlids;

    /** Mirrors this table's DB-level defaults — see CRM's Customer model for why. */
    protected $attributes = [
        'status' => 'active',
        'stock_value' => 0,
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'stock_value' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_warehouses');
    }

    /**
     * Warehouse-level scoping (spec §2, layer 1): a user assigned to specific
     * warehouses only sees those, unless they hold a role with oversight
     * across the whole tenant. Administrator already bypasses every Gate
     * check (see CoreServiceProvider), so it never reaches this scope.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(['Owner', 'Auditor'])) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $q) => $q->whereKey($user->id));
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->hasAnyRole(['Owner', 'Auditor'])) {
            return true;
        }

        return $this->users()->whereKey($user->id)->exists();
    }

    /**
     * SUM(batch.qty_cartons * product.cost_price) across this warehouse,
     * where product.cost_price = pack_cost_price × carton_qty (spec §5.1.7).
     */
    public function recalculateStockValue(): void
    {
        $value = $this->batches()
            ->join('products', 'products.id', '=', 'inventory_batches.product_id')
            ->selectRaw('COALESCE(SUM(inventory_batches.qty_cartons * products.pack_cost_price * products.carton_qty), 0) as total')
            ->value('total');

        $this->forceFill(['stock_value' => $value])->save();
    }

    protected static function newFactory(): WarehouseFactory
    {
        return WarehouseFactory::new();
    }
}

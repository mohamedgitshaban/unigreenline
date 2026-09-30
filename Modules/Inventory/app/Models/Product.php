<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Database\Factories\ProductFactory;

#[Fillable([
    'tenant_id', 'category_id', 'supplier_id', 'name', 'sku', 'brand', 'pack_unit',
    'carton_qty', 'pack_cost_price', 'pack_selling_price', 'discount_pct', 'tax_pct',
    'min_stock_cartons', 'reorder_level', 'active',
])]
class Product extends Model
{
    use HasFactory, HasUlids;

    protected $appends = ['cost_price', 'selling_price'];

    /** Mirrors this table's DB-level defaults — see CRM's Customer model for why. */
    protected $attributes = [
        'discount_pct' => 0,
        'tax_pct' => 14,
        'min_stock_cartons' => 0,
        'reorder_level' => 0,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'carton_qty' => 'integer',
            'pack_cost_price' => 'decimal:2',
            'pack_selling_price' => 'decimal:2',
            'discount_pct' => 'decimal:2',
            'tax_pct' => 'decimal:2',
            'min_stock_cartons' => 'integer',
            'reorder_level' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * cost_price and selling_price are computed, not stored — see the
     * migration's docblock for why.
     */
    protected function costPrice(): Attribute
    {
        // number_format, not round(): pack_cost_price is a decimal-cast
        // string, and every other money field in this API serializes as a
        // fixed 2-decimal string — this keeps cost_price consistent with them.
        return Attribute::get(fn () => number_format($this->pack_cost_price * $this->carton_qty, 2, '.', ''));
    }

    protected function sellingPrice(): Attribute
    {
        return Attribute::get(fn () => number_format($this->pack_selling_price * $this->carton_qty, 2, '.', ''));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\Modules\Purchasing\Models\Supplier::class, 'supplier_id');
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}

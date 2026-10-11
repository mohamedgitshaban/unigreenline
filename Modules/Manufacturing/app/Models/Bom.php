<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Product;
use Modules\Manufacturing\Database\Factories\BomFactory;

/**
 * Bill of materials: the recipe for one finished product — which component
 * products, and how many cartons of each, one run consumes, and how many
 * cartons of the finished product one run yields.
 */
#[Fillable(['tenant_id', 'product_id', 'name', 'output_qty_cartons', 'conversion_cost', 'notes', 'active'])]
class Bom extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = [
        'output_qty_cartons' => 1,
        'conversion_cost' => 0,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'output_qty_cartons' => 'integer',
            'conversion_cost' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class);
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    protected static function newFactory(): BomFactory
    {
        return BomFactory::new();
    }
}

<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;
use Modules\Manufacturing\Database\Factories\ProductPlanningFactory;

/**
 * How material planning treats a product: made or bought, lead time and lot-sizing rule.
 */
#[Fillable(['tenant_id', 'product_id', 'procurement', 'lead_time_days', 'lot_rule', 'lot_qty', 'min_qty', 'max_qty', 'multiple_qty', 'make_to_order'])]
class ProductPlanning extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'product_planning';

    protected $attributes = ['lead_time_days' => 0, 'lot_rule' => 'exact', 'make_to_order' => false];

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'lot_qty' => 'integer',
            'min_qty' => 'integer',
            'max_qty' => 'integer',
            'multiple_qty' => 'integer',
            'make_to_order' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function newFactory(): ProductPlanningFactory
    {
        return ProductPlanningFactory::new();
    }
}

<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;

/**
 * A second output of a recipe (a by-product): cartons per run, the share of the
 * run's cost it carries, and where it is received.
 */
#[Fillable(['bom_id', 'product_id', 'qty_cartons', 'cost_share_pct', 'warehouse_id'])]
class BomByProduct extends Model
{
    use HasUlids;

    protected $table = 'bom_by_products';

    protected function casts(): array
    {
        return ['qty_cartons' => 'integer', 'cost_share_pct' => 'decimal:2'];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}

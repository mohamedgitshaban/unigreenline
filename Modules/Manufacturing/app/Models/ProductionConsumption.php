<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;

/**
 * One batch's worth of one component taken by a production order: consumed by the
 * product (`consume`) or wasted on the way (`scrap`).
 */
#[Fillable([
    'production_order_id', 'component_id', 'work_order_id', 'kind', 'absorbed', 'product_id', 'batch_id', 'batch_no',
    'qty_cartons', 'cost_per_carton', 'line_value', 'journal_entry_id', 'recorded_by',
])]
class ProductionConsumption extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'qty_cartons' => 'integer',
            'cost_per_carton' => 'decimal:2',
            'line_value' => 'decimal:2',
            'absorbed' => 'boolean',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    protected $attributes = ['kind' => 'consume', 'absorbed' => false];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

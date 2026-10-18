<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;

/**
 * A material an order needs, fixed when the order is confirmed.
 * `required_qty` is the exact need (cartons, with the scrap allowance);
 * `required_cartons` is the whole cartons stock is issued in.
 */
#[Fillable([
    'production_order_id', 'product_id', 'bom_line_id', 'required_qty', 'required_cartons', 'reserved_cartons',
    'consumed_cartons', 'scrap_pct', 'optional', 'work_order_id',
])]
class ProductionOrderComponent extends Model
{
    use HasUlids;

    protected $attributes = ['reserved_cartons' => 0, 'consumed_cartons' => 0, 'scrap_pct' => 0, 'optional' => false];

    protected function casts(): array
    {
        return [
            'required_qty' => 'decimal:4',
            'required_cartons' => 'integer',
            'reserved_cartons' => 'integer',
            'consumed_cartons' => 'integer',
            'scrap_pct' => 'decimal:2',
            'optional' => 'boolean',
        ];
    }

    public function remainingCartons(): int
    {
        return max(0, $this->required_cartons - $this->consumed_cartons);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}

<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cartons of one batch set aside for a document. While `active` they count as
 * reserved on the batch (`inventory_batches.reserved_cartons`) and nobody else
 * may take them.
 */
#[Fillable([
    'tenant_id', 'product_id', 'warehouse_id', 'batch_id', 'qty_cartons', 'status',
    'source_type', 'source_id', 'source_line_id', 'created_by',
])]
class StockReservation extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['qty_cartons' => 'integer'];
    }

    /**
     * @param  Builder<StockReservation>  $query
     * @return Builder<StockReservation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\JournalEntry;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;

/**
 * One receipt into stock from an order: the finished product, or a by-product.
 */
#[Fillable([
    'production_order_id', 'work_order_id', 'kind', 'product_id', 'batch_id', 'batch_no', 'qty_cartons', 'rejected_cartons',
    'cost_per_carton', 'line_value', 'journal_entry_id', 'recorded_by', 'notes',
])]
class ProductionOutput extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'qty_cartons' => 'integer',
            'rejected_cartons' => 'integer',
            'cost_per_carton' => 'decimal:2',
            'line_value' => 'decimal:2',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}

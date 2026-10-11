<?php

namespace Modules\Recap\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;

#[Fillable(['recap_id', 'batch_id', 'product_id', 'batch_no', 'exp_date', 'qty_cartons', 'cost_per_carton', 'line_value'])]
class RecapLine extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'exp_date' => 'date',
            'qty_cartons' => 'integer',
            'cost_per_carton' => 'decimal:2',
            'line_value' => 'decimal:2',
        ];
    }

    public function recap(): BelongsTo
    {
        return $this->belongsTo(Recap::class);
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

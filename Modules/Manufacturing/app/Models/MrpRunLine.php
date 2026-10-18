<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;

#[Fillable([
    'mrp_run_id', 'product_id', 'warehouse_id', 'level', 'gross_requirement', 'on_hand', 'reserved', 'incoming',
    'safety_stock', 'net_requirement', 'planned_qty', 'due_date', 'action', 'sources',
])]
class MrpRunLine extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'gross_requirement' => 'decimal:4', 'incoming' => 'decimal:4', 'net_requirement' => 'decimal:4',
            'on_hand' => 'integer', 'reserved' => 'integer', 'safety_stock' => 'integer', 'planned_qty' => 'integer', 'level' => 'integer',
            'due_date' => 'date', 'sources' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MrpRun::class, 'mrp_run_id');
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

<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;

/**
 * A proposed order from planning. Nothing is ordered until someone converts it.
 */
#[Fillable([
    'tenant_id', 'mrp_run_id', 'type', 'product_id', 'warehouse_id', 'qty_cartons', 'start_date', 'due_date', 'supplier_id',
    'status', 'fingerprint', 'open_key', 'sources', 'converted_type', 'converted_id',
])]
class MrpSuggestion extends Model
{
    use HasUlids;

    protected $attributes = ['status' => 'proposed'];

    protected function casts(): array
    {
        return ['qty_cartons' => 'integer', 'start_date' => 'date', 'due_date' => 'date', 'sources' => 'array'];
    }

    public function isProposed(): bool
    {
        return $this->status === 'proposed';
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}

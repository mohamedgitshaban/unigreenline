<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Database\Factories\MrpDemandFactory;

/**
 * A planned requirement that is not a sales order: a manual need or a forecast.
 */
#[Fillable(['tenant_id', 'product_id', 'warehouse_id', 'qty_cartons', 'due_date', 'kind', 'status', 'notes', 'created_by'])]
class MrpDemand extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = ['kind' => 'manual', 'status' => 'open'];

    protected function casts(): array
    {
        return ['qty_cartons' => 'integer', 'due_date' => 'date'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    protected static function newFactory(): MrpDemandFactory
    {
        return MrpDemandFactory::new();
    }
}

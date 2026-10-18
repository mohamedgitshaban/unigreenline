<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;

#[Fillable(['bom_id', 'component_product_id', 'qty_cartons', 'scrap_pct', 'optional', 'unit_id', 'unit_qty', 'operation_id'])]
class BomLine extends Model
{
    use HasUlids;

    protected $attributes = ['scrap_pct' => 0, 'optional' => false];

    protected function casts(): array
    {
        return ['qty_cartons' => 'integer', 'scrap_pct' => 'decimal:2', 'optional' => 'boolean', 'unit_qty' => 'integer'];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(RoutingOperation::class, 'operation_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}

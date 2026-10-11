<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;

#[Fillable(['bom_id', 'component_product_id', 'qty_cartons'])]
class BomLine extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['qty_cartons' => 'integer'];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}

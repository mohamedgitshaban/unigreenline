<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;
use Modules\Purchasing\Database\Factories\PurchaseOrderLineFactory;

#[Fillable(['po_id', 'product_id', 'qty_cartons', 'cost_per_carton', 'total'])]
class PurchaseOrderLine extends Model
{
    use HasFactory, HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty_cartons' => 'integer',
            'cost_per_carton' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function newFactory(): PurchaseOrderLineFactory
    {
        return PurchaseOrderLineFactory::new();
    }
}

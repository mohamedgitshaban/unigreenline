<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Database\Factories\SalesOrderLineFactory;

#[Fillable(['so_id', 'product_id', 'warehouse_id', 'batch_no', 'qty', 'unit', 'unit_id', 'base_qty', 'unit_price', 'discount_pct', 'free_qty', 'is_gift', 'subtotal'])]
class SalesOrderLine extends Model
{
    use HasFactory, HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'decimal:2',
            'discount_pct' => 'decimal:2',
            'free_qty' => 'integer',
            'is_gift' => 'boolean',
            'base_qty' => 'integer',
            'subtotal' => 'decimal:2',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function newFactory(): SalesOrderLineFactory
    {
        return SalesOrderLineFactory::new();
    }
}

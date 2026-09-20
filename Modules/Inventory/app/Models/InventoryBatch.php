<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Database\Factories\InventoryBatchFactory;

#[Fillable([
    'tenant_id', 'product_id', 'warehouse_id', 'batch_no', 'mfg_date', 'exp_date',
    'rcv_date', 'qty_cartons', 'qty_packs', 'cost_per_carton',
])]
class InventoryBatch extends Model
{
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'mfg_date' => 'date',
            'exp_date' => 'date',
            'rcv_date' => 'date',
            'qty_cartons' => 'integer',
            'qty_packs' => 'integer',
            'cost_per_carton' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    protected static function newFactory(): InventoryBatchFactory
    {
        return InventoryBatchFactory::new();
    }
}

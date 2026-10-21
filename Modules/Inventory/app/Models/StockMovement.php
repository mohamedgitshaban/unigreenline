<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Inventory\Database\Factories\StockMovementFactory;
use Modules\Inventory\Observers\InventoryBatchObserver;

/**
 * One line of the stock ledger: cartons that entered (positive) or left (negative) a batch in a warehouse,
 * and why. Written by {@see InventoryBatchObserver} whenever a batch quantity
 * changes, so the movements of a batch always add up to what it holds. Never edited.
 */
#[Fillable([
    'tenant_id', 'warehouse_id', 'product_id', 'batch_id', 'batch_no', 'qty_cartons', 'unit_cost', 'is_free_gift',
    'type', 'reference_type', 'reference_id', 'counterpart_warehouse_id', 'user_id', 'notes', 'occurred_at',
])]
class StockMovement extends Model
{
    use HasFactory, HasUlids;

    public $timestamps = false;

    /** What each movement type is called to a person, and which way it goes. */
    public const TYPES = [
        'opening_balance' => 'Opening balance',
        'goods_receipt' => 'Goods receipt',
        'purchase_receipt' => 'Purchase order receipt',
        'transfer_in' => 'Transfer in',
        'transfer_out' => 'Transfer out',
        'sale' => 'Sale',
        'sale_return' => 'Customer return (restocked)',
        'return_to_supplier' => 'Return to supplier',
        'write_off' => 'Write-off',
        'production_consumption' => 'Used in production',
        'production_output' => 'Produced',
        'production_by_product' => 'By-product',
        'adjustment' => 'Adjustment',
    ];

    protected function casts(): array
    {
        return [
            'qty_cartons' => 'integer',
            'unit_cost' => 'decimal:2',
            'is_free_gift' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function counterpartWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'counterpart_warehouse_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function direction(): string
    {
        return $this->qty_cartons >= 0 ? 'in' : 'out';
    }

    protected static function newFactory(): StockMovementFactory
    {
        return StockMovementFactory::new();
    }
}

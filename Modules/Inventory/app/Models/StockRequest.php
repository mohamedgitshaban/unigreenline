<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\HasDocumentNumber;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Database\Factories\StockRequestFactory;

/**
 * A warehouse asking for stock, from another warehouse, from Purchasing or from Manufacturing. Granting it turns
 * it into a transfer, a draft purchase order or a draft production order (see StockRequestService).
 */
#[Fillable([
    'tenant_id', 'warehouse_id', 'source', 'from_warehouse_id', 'product_id', 'qty_cartons', 'unit_id', 'unit_qty', 'needed_by', 'notes',
    'status', 'requested_by', 'decided_by', 'decided_at', 'rejection_reason', 'result_type', 'result_id',
])]
class StockRequest extends Model
{
    use HasDocumentNumber, HasFactory, HasUlids;

    /**
     * @return array{0: string, 1: string}
     */
    public static function documentSequence(): array
    {
        return ['stock_request', 'SRQ-'];
    }

    protected $attributes = ['status' => 'requested'];

    protected function casts(): array
    {
        return [
            'qty_cartons' => 'integer',
            'needed_by' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    protected static function newFactory(): StockRequestFactory
    {
        return StockRequestFactory::new();
    }
}

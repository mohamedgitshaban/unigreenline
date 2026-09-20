<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;
use Modules\Sales\Database\Factories\ReturnRecordFactory;

/**
 * Named ReturnRecord, not Return — "return" is a reserved word and cannot
 * be used as a PHP class name. Maps to the "returns" table (spec §4.11).
 */
#[Fillable([
    'tenant_id', 'invoice_id', 'customer_id', 'supplier_id', 'product_id', 'warehouse_id',
    'batch_no', 'type', 'qty', 'unit', 'amount', 'restocked', 'reason', 'status', 'return_date',
])]
class ReturnRecord extends Model
{
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'returns';

    protected $attributes = [
        'status' => 'completed',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'amount' => 'decimal:2',
            'restocked' => 'boolean',
            'return_date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    protected static function newFactory(): ReturnRecordFactory
    {
        return ReturnRecordFactory::new();
    }
}

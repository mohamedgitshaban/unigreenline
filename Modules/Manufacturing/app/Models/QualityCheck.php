<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;

#[Fillable([
    'tenant_id', 'definition_id', 'name', 'point', 'type', 'min_value', 'max_value', 'mandatory',
    'production_order_id', 'work_order_id', 'product_id', 'reference_type', 'reference_id',
    'status', 'measured_value', 'defect_class', 'rejected_cartons', 'notes', 'checked_by', 'checked_at',
])]
class QualityCheck extends Model
{
    use HasUlids;

    protected $attributes = ['status' => 'pending', 'mandatory' => true, 'rejected_cartons' => 0];

    protected function casts(): array
    {
        return [
            'min_value' => 'decimal:4',
            'max_value' => 'decimal:4',
            'measured_value' => 'decimal:4',
            'mandatory' => 'boolean',
            'rejected_cartons' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(QualityAlert::class);
    }
}

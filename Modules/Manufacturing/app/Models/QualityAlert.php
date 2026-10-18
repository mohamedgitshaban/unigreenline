<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;

#[Fillable(['tenant_id', 'quality_check_id', 'production_order_id', 'product_id', 'severity', 'description', 'corrective_action', 'status', 'created_by', 'resolved_by', 'resolved_at'])]
class QualityAlert extends Model
{
    use HasUlids;

    protected $attributes = ['severity' => 'medium', 'status' => 'open'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(QualityCheck::class, 'quality_check_id');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

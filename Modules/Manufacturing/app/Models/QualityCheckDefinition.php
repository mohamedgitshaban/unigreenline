<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;
use Modules\Manufacturing\Database\Factories\QualityCheckDefinitionFactory;

#[Fillable(['tenant_id', 'name', 'point', 'type', 'product_id', 'routing_operation_id', 'min_value', 'max_value', 'unit_label', 'mandatory', 'instructions', 'active'])]
class QualityCheckDefinition extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = ['point' => 'final', 'type' => 'pass_fail', 'mandatory' => true, 'active' => true];

    protected function casts(): array
    {
        return ['min_value' => 'decimal:4', 'max_value' => 'decimal:4', 'mandatory' => 'boolean', 'active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(RoutingOperation::class, 'routing_operation_id');
    }

    protected static function newFactory(): QualityCheckDefinitionFactory
    {
        return QualityCheckDefinitionFactory::new();
    }
}

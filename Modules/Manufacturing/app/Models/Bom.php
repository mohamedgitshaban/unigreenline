<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Product;
use Modules\Manufacturing\Database\Factories\BomFactory;

/**
 * Bill of materials: the recipe for one finished product — which component
 * products, and how many cartons of each, one run consumes, and how many
 * cartons of the finished product one run yields.
 */
#[Fillable([
    'tenant_id', 'code', 'product_id', 'name', 'version', 'status', 'priority', 'effective_from', 'effective_to', 'routing_id',
    'output_qty_cartons', 'conversion_cost', 'notes', 'instructions', 'active', 'approved_by', 'approved_at', 'archived_at',
])]
class Bom extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = [
        'output_qty_cartons' => 1,
        'conversion_cost' => 0,
        'active' => true,
        'version' => 1,
        'status' => 'approved',
        'priority' => 10,
    ];

    protected function casts(): array
    {
        return [
            'output_qty_cartons' => 'integer',
            'conversion_cost' => 'decimal:2',
            'active' => 'boolean',
            'version' => 'integer',
            'priority' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Whether new orders may be raised from it on `$date`: approved, not archived, inside its validity window.
     */
    public function isUsableOn(?\DateTimeInterface $date = null): bool
    {
        $day = ($date ?? now())->format('Y-m-d');

        return $this->active
            && $this->status === 'approved'
            && ($this->effective_from === null || $this->effective_from->format('Y-m-d') <= $day)
            && ($this->effective_to === null || $this->effective_to->format('Y-m-d') >= $day);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class);
    }

    public function byProducts(): HasMany
    {
        return $this->hasMany(BomByProduct::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BomRevision::class)->orderBy('revision_no');
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class);
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    protected static function newFactory(): BomFactory
    {
        return BomFactory::new();
    }
}

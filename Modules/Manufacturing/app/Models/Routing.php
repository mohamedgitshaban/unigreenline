<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant;
use Modules\Manufacturing\Database\Factories\RoutingFactory;

/**
 * The ordered operations needed to make a product.
 */
#[Fillable(['tenant_id', 'code', 'name', 'notes', 'active'])]
class Routing extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(RoutingOperation::class)->orderBy('sequence');
    }

    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class);
    }

    protected static function newFactory(): RoutingFactory
    {
        return RoutingFactory::new();
    }
}

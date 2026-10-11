<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Database\Factories\UnitFactory;

/**
 * A packaging unit name (Packet, Package, Carton...). What each unit means
 * for a given product — how many of the one below it it holds — lives on
 * ProductUnit.
 */
#[Fillable(['tenant_id', 'name', 'active'])]
class Unit extends Model
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

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }
}

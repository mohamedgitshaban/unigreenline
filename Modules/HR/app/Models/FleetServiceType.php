<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HR\Database\Factories\FleetServiceTypeFactory;

/**
 * A kind of service or repair (oil change, tyres, inspection ...).
 */
#[Fillable(['tenant_id', 'name', 'category'])]
class FleetServiceType extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'hr_fleet_service_types';

    protected $attributes = [
        'category' => 'service',
    ];

    public function services(): HasMany
    {
        return $this->hasMany(VehicleService::class, 'service_type_id');
    }

    protected static function newFactory(): FleetServiceTypeFactory
    {
        return FleetServiceTypeFactory::new();
    }
}

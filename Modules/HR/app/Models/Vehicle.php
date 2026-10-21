<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HR\Database\Factories\VehicleFactory;

/**
 * A company vehicle, who drives it now, and its running record.
 */
#[Fillable(['tenant_id', 'license_plate', 'vin', 'brand', 'model', 'model_year', 'vehicle_type', 'fuel_type', 'transmission', 'color', 'seats', 'odometer', 'acquisition_date', 'acquisition_cost', 'residual_value', 'status', 'driver_employee_id', 'fixed_asset_id', 'location', 'notes', 'created_by'])]
class Vehicle extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'hr_vehicles';

    protected $attributes = [
        'status' => 'active',
        'odometer' => 0,
        'vehicle_type' => 'car',
        'fuel_type' => 'gasoline',
    ];

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'seats' => 'integer',
            'odometer' => 'decimal:1',
            'acquisition_date' => 'date',
            'acquisition_cost' => 'decimal:2',
            'residual_value' => 'decimal:2',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_employee_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class, 'vehicle_id');
    }

    public function odometerLogs(): HasMany
    {
        return $this->hasMany(VehicleOdometerLog::class, 'vehicle_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(VehicleService::class, 'vehicle_id');
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(VehicleFuelLog::class, 'vehicle_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(VehicleContract::class, 'vehicle_id');
    }

    protected static function newFactory(): VehicleFactory
    {
        return VehicleFactory::new();
    }
}

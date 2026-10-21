<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refuelling.
 */
#[Fillable(['tenant_id', 'vehicle_id', 'fueled_on', 'liters', 'price_per_liter', 'amount', 'odometer', 'full_tank', 'vendor_name', 'employee_id', 'notes', 'created_by'])]
class VehicleFuelLog extends Model
{
    use HasUlids;

    protected $table = 'hr_vehicle_fuel_logs';

    protected $attributes = [
        'full_tank' => true,
    ];

    protected function casts(): array
    {
        return [
            'fueled_on' => 'date',
            'liters' => 'decimal:2',
            'price_per_liter' => 'decimal:3',
            'amount' => 'decimal:2',
            'odometer' => 'decimal:1',
            'full_tank' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

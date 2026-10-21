<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reading of the vehicle's odometer.
 */
#[Fillable(['tenant_id', 'vehicle_id', 'logged_on', 'value', 'employee_id', 'notes', 'created_by'])]
class VehicleOdometerLog extends Model
{
    use HasUlids;

    protected $table = 'hr_vehicle_odometer_logs';

    protected function casts(): array
    {
        return [
            'logged_on' => 'date',
            'value' => 'decimal:1',
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

<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period during which an employee drove a vehicle. No end date means they drive it now.
 */
#[Fillable(['tenant_id', 'vehicle_id', 'employee_id', 'starts_on', 'ends_on', 'notes', 'created_by'])]
class VehicleAssignment extends Model
{
    use HasUlids;

    protected $table = 'hr_vehicle_assignments';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
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

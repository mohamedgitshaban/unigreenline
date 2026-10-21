<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service or repair of a vehicle, planned or done, with what it cost and when the next one is due.
 */
#[Fillable(['tenant_id', 'vehicle_id', 'service_type_id', 'service_date', 'status', 'odometer', 'cost', 'supplier_id', 'vendor_name', 'reference', 'description', 'next_due_date', 'next_due_odometer', 'last_reminded_on', 'created_by'])]
class VehicleService extends Model
{
    use HasUlids;

    protected $table = 'hr_vehicle_services';

    protected $attributes = [
        'status' => 'planned',
        'cost' => 0,
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'odometer' => 'decimal:1',
            'cost' => 'decimal:2',
            'next_due_date' => 'date',
            'next_due_odometer' => 'decimal:1',
            'last_reminded_on' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(FleetServiceType::class, 'service_type_id');
    }
}

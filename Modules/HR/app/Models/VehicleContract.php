<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Insurance, leasing, registration or another contract that covers a vehicle for a period.
 */
#[Fillable(['tenant_id', 'vehicle_id', 'type', 'reference', 'supplier_id', 'vendor_name', 'starts_on', 'expires_on', 'cost', 'frequency', 'status', 'reminder_days', 'last_reminded_on', 'notes', 'created_by'])]
class VehicleContract extends Model
{
    use HasUlids;

    protected $table = 'hr_vehicle_contracts';

    protected $attributes = [
        'status' => 'active',
        'type' => 'insurance',
        'frequency' => 'yearly',
        'reminder_days' => 30,
        'cost' => 0,
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'expires_on' => 'date',
            'cost' => 'decimal:2',
            'reminder_days' => 'integer',
            'last_reminded_on' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}

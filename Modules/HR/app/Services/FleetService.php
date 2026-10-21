<?php

namespace Modules\HR\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleAssignment;
use Modules\HR\Models\VehicleFuelLog;
use Modules\HR\Models\VehicleOdometerLog;
use Modules\HR\Models\VehicleService;

/**
 * The running record of a company vehicle: who drives it, how far it has gone, what it burns and what it costs to keep.
 */
class FleetService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * Gives the vehicle to a driver from a date (or takes it away from its driver when `$employee` is null).
     * The previous driver's turn ends the day before.
     */
    public function assignDriver(Vehicle $vehicle, ?Employee $employee, CarbonImmutable $date, ?string $notes, User $actor): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $employee, $date, $notes, $actor) {
            $locked = $this->lock($vehicle);

            if ($employee !== null && $employee->status !== 'active') {
                throw new HrRuleException('Only an active employee can be given a vehicle.');
            }

            if ($locked->status === 'sold') {
                throw new HrRuleException('A sold vehicle cannot be assigned.');
            }

            $previous = $locked->driver_employee_id;
            $open = VehicleAssignment::query()->where('vehicle_id', $locked->id)->whereNull('ends_on')->lockForUpdate()->get();

            foreach ($open as $assignment) {
                $assignment->forceFill(['ends_on' => $date->subDay()->max(CarbonImmutable::parse($assignment->starts_on))])->save();
            }

            if ($employee !== null) {
                VehicleAssignment::create([
                    'tenant_id' => $locked->tenant_id, 'vehicle_id' => $locked->id, 'employee_id' => $employee->id,
                    'starts_on' => $date, 'notes' => $notes, 'created_by' => $actor->id,
                ]);
            }

            $locked->forceFill(['driver_employee_id' => $employee?->id])->save();
            $this->audit($actor, $locked, 'UPDATE', ['driver_employee_id' => $previous], ['driver_employee_id' => $employee?->id]);

            return $locked->refresh();
        });
    }

    /**
     * Writes down an odometer reading. A reading must fit between the ones before and after it in date order;
     * the newest reading becomes the vehicle's odometer.
     */
    public function logOdometer(Vehicle $vehicle, float $value, CarbonImmutable $date, ?string $employeeId, ?string $notes, User $actor): VehicleOdometerLog
    {
        return DB::transaction(function () use ($vehicle, $value, $date, $employeeId, $notes, $actor) {
            $locked = $this->lock($vehicle);

            $before = VehicleOdometerLog::query()->where('vehicle_id', $locked->id)->whereDate('logged_on', '<=', $date->toDateString())->orderByDesc('logged_on')->orderByDesc('value')->first();
            $after = VehicleOdometerLog::query()->where('vehicle_id', $locked->id)->whereDate('logged_on', '>', $date->toDateString())->orderBy('logged_on')->orderBy('value')->first();

            if ($before && $value < (float) $before->value) {
                throw new HrRuleException("The odometer cannot go backwards: it read {$before->value} on {$before->logged_on->toDateString()}.");
            }

            if ($after && $value > (float) $after->value) {
                throw new HrRuleException("This reading is higher than the later one of {$after->value} on {$after->logged_on->toDateString()}.");
            }

            if ($before === null && $after === null && $value < (float) $locked->odometer) {
                throw new HrRuleException("The odometer cannot go backwards: the vehicle is at {$locked->odometer}.");
            }

            $log = VehicleOdometerLog::create([
                'tenant_id' => $locked->tenant_id, 'vehicle_id' => $locked->id, 'logged_on' => $date, 'value' => $value,
                'employee_id' => $employeeId ?? $locked->driver_employee_id, 'notes' => $notes, 'created_by' => $actor->id,
            ]);

            if ($after === null && $value >= (float) $locked->odometer) {
                $locked->forceFill(['odometer' => $value])->save();
            }

            return $log;
        });
    }

    /**
     * Records a refuelling: the amount or the price per litre may be left out and is worked out from the other; a reading, if given, goes on the odometer log.
     *
     * @param  array<string, mixed>  $data
     */
    public function refuel(Vehicle $vehicle, array $data, User $actor): VehicleFuelLog
    {
        return DB::transaction(function () use ($vehicle, $data, $actor) {
            $liters = round((float) $data['liters'], 2);
            $price = isset($data['price_per_liter']) ? round((float) $data['price_per_liter'], 3) : null;
            $amount = isset($data['amount']) ? round((float) $data['amount'], 2) : null;

            if ($amount === null && $price !== null) {
                $amount = round($liters * $price, 2);
            } elseif ($price === null && $amount !== null) {
                $price = round($amount / $liters, 3);
            }

            $date = CarbonImmutable::parse($data['fueled_on']);

            if (isset($data['odometer'])) {
                $this->logOdometer($vehicle, (float) $data['odometer'], $date, $data['employee_id'] ?? null, 'Refuelling', $actor);
            }

            return VehicleFuelLog::create([
                'tenant_id' => $vehicle->tenant_id, 'vehicle_id' => $vehicle->id, 'fueled_on' => $date, 'liters' => $liters,
                'price_per_liter' => $price ?? 0, 'amount' => $amount ?? 0, 'odometer' => $data['odometer'] ?? null,
                'full_tank' => $data['full_tank'] ?? true, 'vendor_name' => $data['vendor_name'] ?? null,
                'employee_id' => $data['employee_id'] ?? $vehicle->driver_employee_id, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * Marks a service done: its date, cost and odometer reading as they turned out; the reading goes on the odometer log.
     *
     * @param  array<string, mixed>  $data
     */
    public function completeService(VehicleService $service, array $data, User $actor): VehicleService
    {
        return DB::transaction(function () use ($service, $data, $actor) {
            $locked = VehicleService::query()->where('tenant_id', $service->tenant_id)->whereKey($service->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'planned') {
                throw new HrRuleException($locked->status === 'done' ? 'This service is already done.' : 'A cancelled service cannot be completed.');
            }

            $vehicle = Vehicle::query()->findOrFail($locked->vehicle_id);
            $date = CarbonImmutable::parse($data['service_date'] ?? $locked->service_date);

            if (isset($data['odometer'])) {
                $this->logOdometer($vehicle, (float) $data['odometer'], $date, null, "Service: {$locked->description}", $actor);
            }

            $locked->forceFill(array_filter([
                'status' => 'done', 'service_date' => $date, 'odometer' => $data['odometer'] ?? $locked->odometer,
                'cost' => $data['cost'] ?? $locked->cost, 'next_due_date' => $data['next_due_date'] ?? $locked->next_due_date,
                'next_due_odometer' => $data['next_due_odometer'] ?? $locked->next_due_odometer,
            ], fn ($value) => $value !== null))->save();

            if ($vehicle->status === 'in_maintenance' && ! VehicleService::query()->where('vehicle_id', $vehicle->id)->where('status', 'planned')->exists()) {
                $vehicle->forceFill(['status' => 'active'])->save();
            }

            return $locked->refresh();
        });
    }

    private function lock(Vehicle $vehicle): Vehicle
    {
        return Vehicle::query()->where('tenant_id', $vehicle->tenant_id)->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     */
    private function audit(User $actor, Vehicle $vehicle, string $operation, ?array $previous, ?array $new): void
    {
        $this->auditLog->record([
            'module' => 'hr', 'entity_type' => 'Vehicle', 'entity_id' => $vehicle->id, 'operation' => $operation,
            'user_id' => $actor->id, 'user_name' => $actor->name, 'tenant_id' => $vehicle->tenant_id,
            'prev_values' => $previous, 'new_values' => $new,
        ]);
    }
}

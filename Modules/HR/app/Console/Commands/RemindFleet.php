<?php

namespace Modules\HR\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Models\Notification;
use Modules\Core\Models\User;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleContract;
use Modules\HR\Models\VehicleService;

/**
 * Tells the people who run the fleet (and the driver) about contracts that are running out or have run out, and about services
 * that are due by date or by odometer. Once a day per item.
 */
class RemindFleet extends Command
{
    protected $signature = 'fleet:remind';

    protected $description = 'Notify about vehicle contracts expiring and services falling due';

    public function handle(): int
    {
        $told = 0;

        VehicleContract::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('last_reminded_on')->orWhereDate('last_reminded_on', '<', today()))
            ->get()->filter(fn (VehicleContract $c) => $c->expires_on->lte(today()->addDays($c->reminder_days)))
            ->each(function (VehicleContract $contract) use (&$told) {
                $vehicle = Vehicle::query()->find($contract->vehicle_id);
                $late = $contract->expires_on->lt(today());
                $told += $this->tell($vehicle, 'vehicle-contracts.update', $late ? 'fleet.contract_expired' : 'fleet.contract_expiring',
                    $late ? "{$contract->type} of {$vehicle->license_plate} has expired" : "{$contract->type} of {$vehicle->license_plate} expires soon",
                    "The {$contract->type} contract ".($late ? 'ended' : 'ends').' on '.$contract->expires_on->toDateString().'.');
                $contract->forceFill(['last_reminded_on' => today()])->save();
            });

        VehicleService::query()->whereIn('status', ['planned', 'done'])
            ->where(fn ($q) => $q->whereNull('last_reminded_on')->orWhereDate('last_reminded_on', '<', today()))
            ->with(['vehicle', 'serviceType'])->get()
            ->filter(fn (VehicleService $s) => $this->isDue($s))
            ->each(function (VehicleService $service) use (&$told) {
                $name = $service->serviceType?->name ?? 'Service';
                $told += $this->tell($service->vehicle, 'vehicle-services.update', 'fleet.service_due', "{$name} due for {$service->vehicle->license_plate}",
                    $service->status === 'planned' ? "A {$name} is planned for {$service->service_date->toDateString()}." : "The next {$name} is due".($service->next_due_date ? " by {$service->next_due_date->toDateString()}" : '').($service->next_due_odometer ? " or at {$service->next_due_odometer}" : '').'.');
                $service->forceFill(['last_reminded_on' => today()])->save();
            });

        $this->info("{$told} reminder(s) created.");

        return self::SUCCESS;
    }

    /**
     * A planned service is due a week ahead; a finished one reminds when the next one is due (by date, or within 500 of the odometer)
     * unless a later service of the same kind has been done or planned since.
     */
    private function isDue(VehicleService $service): bool
    {
        if ($service->status === 'planned') {
            return $service->service_date->lte(today()->addDays(7));
        }

        $dueByDate = $service->next_due_date !== null && $service->next_due_date->lte(today()->addDays(14));
        $dueByOdometer = $service->next_due_odometer !== null && (float) $service->vehicle->odometer >= (float) $service->next_due_odometer - 500;

        if (! $dueByDate && ! $dueByOdometer) {
            return false;
        }

        return ! VehicleService::query()->where('vehicle_id', $service->vehicle_id)->where('service_type_id', $service->service_type_id)
            ->whereIn('status', ['planned', 'done'])->where(fn ($q) => $q->whereDate('service_date', '>', $service->service_date)->orWhere(fn ($same) => $same->whereDate('service_date', $service->service_date)->where('id', '>', $service->id)))->exists();
    }

    private function tell(Vehicle $vehicle, string $permission, string $type, string $title, string $body): int
    {
        $driverUser = $vehicle->driver_employee_id ? Employee::query()->whereKey($vehicle->driver_employee_id)->value('user_id') : null;

        $recipients = User::query()->where('tenant_id', $vehicle->tenant_id)->where('status', 'active')->get()
            ->filter(fn (User $user) => $user->can($permission) || $user->id === $driverUser);

        foreach ($recipients as $user) {
            Notification::create([
                'tenant_id' => $vehicle->tenant_id, 'user_id' => $user->id, 'type' => $type, 'title' => $title, 'body' => $body,
                'icon' => 'alert', 'link_module' => 'hr', 'link_entity' => 'Vehicle', 'link_id' => $vehicle->id, 'link_url' => "/api/v1/vehicles/{$vehicle->id}", 'unread' => true,
            ]);
        }

        return $recipients->count();
    }
}

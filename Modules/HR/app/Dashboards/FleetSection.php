<?php

namespace Modules\HR\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleContract;
use Modules\HR\Models\VehicleService;
use Modules\HR\Support\FleetCosts;

/**
 * The fleet: how many vehicles, what they cost to run, and what needs attention (contracts running out, services due).
 */
class FleetSection implements DashboardSection
{
    public function key(): string
    {
        return 'fleet';
    }

    public function label(): string
    {
        return 'Fleet';
    }

    public function permission(): ?string
    {
        return 'vehicles.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $tenant = $user->tenant_id;
        $now = collect(FleetCosts::perVehicle($tenant, $period));
        $before = collect(FleetCosts::perVehicle($tenant, $period->previous()));
        $byStatus = Vehicle::query()->where('tenant_id', $tenant)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $expiring = VehicleContract::query()->where('hr_vehicle_contracts.tenant_id', $tenant)->where('hr_vehicle_contracts.status', 'active')->whereDate('hr_vehicle_contracts.expires_on', '<=', today()->addDays(30))
            ->join('hr_vehicles', 'hr_vehicles.id', '=', 'hr_vehicle_contracts.vehicle_id')->orderBy('hr_vehicle_contracts.expires_on')->limit(5)
            ->get(['hr_vehicle_contracts.id', 'hr_vehicles.license_plate', 'hr_vehicle_contracts.type', 'hr_vehicle_contracts.expires_on']);

        return [
            'kpis' => [
                Widgets::kpi('vehicles', 'Vehicles in use', (int) (($byStatus['active'] ?? 0) + ($byStatus['in_maintenance'] ?? 0))),
                Widgets::kpi('fleet_cost', 'Running cost', (float) $now->sum('total_cost'), (float) $before->sum('total_cost'), 'currency'),
                Widgets::kpi('fleet_fuel_cost', 'Fuel', (float) $now->sum('fuel_cost'), (float) $before->sum('fuel_cost'), 'currency'),
                Widgets::kpi('fleet_service_cost', 'Services and repairs', (float) $now->sum('service_cost'), (float) $before->sum('service_cost'), 'currency'),
                Widgets::kpi('contracts_expiring', 'Contracts running out within 30 days', VehicleContract::query()->where('tenant_id', $tenant)->where('status', 'active')->whereDate('expires_on', '<=', today()->addDays(30))->count()),
                Widgets::kpi('services_due', 'Services due within 14 days', VehicleService::query()->where('tenant_id', $tenant)->where('status', 'planned')->whereDate('service_date', '<=', today()->addDays(14))->count()),
            ],
            'charts' => [
                Widgets::chart('vehicles_by_status', 'Vehicles by status', 'donut', $byStatus->map(fn ($total, $status) => ['label' => $status, 'value' => (int) $total])->values()->all()),
                Widgets::chart('cost_by_vehicle', 'Running cost by vehicle', 'bar', $now->sortByDesc('total_cost')->take(8)->map(fn ($row) => ['label' => $row['vehicle']['license_plate'], 'value' => $row['total_cost']])->values()->all(), 'currency'),
            ],
            'tables' => [
                Widgets::table('expiring_contracts', 'Contracts running out', [['key' => 'vehicle', 'label' => 'Vehicle'], ['key' => 'type', 'label' => 'Type'], ['key' => 'expires_on', 'label' => 'Expires']],
                    $expiring->map(fn ($row) => ['id' => $row->id, 'vehicle' => $row->license_plate, 'type' => $row->type, 'expires_on' => $row->expires_on->toDateString()])->all()),
            ],
        ];
    }
}

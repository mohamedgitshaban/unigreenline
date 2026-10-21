<?php

namespace Modules\HR\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleContract;

/**
 * What vehicles cost over a period and how hard they work: fuel, services done, the share of each contract that falls in the
 * period, the distance driven and what that makes per kilometre and per litre.
 *
 * A contract costs its amount once on its start date (`once`), or spread evenly over its days — yearly cost over 365 days,
 * monthly cost twelve times a year over 365 days — for the days it covers inside the period.
 */
class FleetCosts
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function perVehicle(string $tenantId, DashboardPeriod $period, ?string $vehicleId = null): array
    {
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        $vehicles = Vehicle::query()->where('tenant_id', $tenantId)->when($vehicleId, fn ($q) => $q->whereKey($vehicleId))->orderBy('license_plate')->get(['id', 'license_plate', 'brand', 'model', 'status']);
        $ids = $vehicles->pluck('id')->all();

        $fuel = DB::table('hr_vehicle_fuel_logs')->whereIn('vehicle_id', $ids)->whereBetween('fueled_on', [$from, $to])
            ->selectRaw('vehicle_id, coalesce(sum(liters), 0) as liters, coalesce(sum(amount), 0) as amount')->groupBy('vehicle_id')->get()->keyBy('vehicle_id');
        $services = DB::table('hr_vehicle_services')->whereIn('vehicle_id', $ids)->where('status', 'done')->whereBetween('service_date', [$from, $to])
            ->selectRaw('vehicle_id, coalesce(sum(cost), 0) as cost')->groupBy('vehicle_id')->get()->keyBy('vehicle_id');
        $contracts = VehicleContract::query()->whereIn('vehicle_id', $ids)->whereDate('starts_on', '<=', $to)->get()->groupBy('vehicle_id');

        return $vehicles->map(function (Vehicle $vehicle) use ($fuel, $services, $contracts, $period, $from, $to) {
            $liters = (float) ($fuel[$vehicle->id]->liters ?? 0);
            $fuelCost = (float) ($fuel[$vehicle->id]->amount ?? 0);
            $serviceCost = (float) ($services[$vehicle->id]->cost ?? 0);
            $contractCost = round(($contracts[$vehicle->id] ?? collect())->sum(fn (VehicleContract $c) => self::contractShare($c, $period)), 2);
            $distance = self::distance($vehicle->id, $from, $to);
            $total = round($fuelCost + $serviceCost + $contractCost, 2);

            return [
                'vehicle' => ['id' => $vehicle->id, 'license_plate' => $vehicle->license_plate, 'name' => trim("{$vehicle->brand} {$vehicle->model}"), 'status' => $vehicle->status],
                'fuel_liters' => round($liters, 2),
                'fuel_cost' => round($fuelCost, 2),
                'service_cost' => round($serviceCost, 2),
                'contract_cost' => $contractCost,
                'total_cost' => $total,
                'distance' => $distance,
                'cost_per_distance' => $distance > 0 ? round($total / $distance, 3) : null,
                'distance_per_liter' => $distance > 0 && $liters > 0 ? round($distance / $liters, 2) : null,
            ];
        })->values()->all();
    }

    public static function contractShare(VehicleContract $contract, DashboardPeriod $period): float
    {
        $cost = (float) $contract->cost;

        if ($contract->frequency === 'once') {
            return $contract->starts_on->between($period->from->startOfDay(), $period->to->endOfDay()) ? $cost : 0.0;
        }

        $start = CarbonImmutable::parse($contract->starts_on)->max($period->from->startOfDay());
        $end = CarbonImmutable::parse($contract->expires_on)->min($period->to->startOfDay());

        if ($start->gt($end)) {
            return 0.0;
        }

        $days = $start->diffInDays($end) + 1;
        $perYear = $contract->frequency === 'monthly' ? $cost * 12 : $cost;

        return round($perYear * $days / 365, 2);
    }

    /**
     * Kilometres driven in the period: the last reading up to its end less the last reading before it began
     * (or the first reading inside it when there is none before).
     */
    private static function distance(string $vehicleId, string $from, string $to): float
    {
        $upTo = DB::table('hr_vehicle_odometer_logs')->where('vehicle_id', $vehicleId)->where('logged_on', '<=', $to)->max('value');
        $before = DB::table('hr_vehicle_odometer_logs')->where('vehicle_id', $vehicleId)->where('logged_on', '<', $from)->max('value')
            ?? DB::table('hr_vehicle_odometer_logs')->where('vehicle_id', $vehicleId)->whereBetween('logged_on', [$from, $to])->min('value');

        return $upTo === null || $before === null ? 0.0 : round(max(0, (float) $upTo - (float) $before), 1);
    }
}

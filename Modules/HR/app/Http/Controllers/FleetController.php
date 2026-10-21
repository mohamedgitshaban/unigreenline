<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleContract;
use Modules\HR\Models\VehicleService;
use Modules\HR\Support\FleetCosts;

/**
 * The fleet at a glance (GET /fleet/analysis): vehicles by status, what the fleet cost over a period and which vehicles cost most.
 */
class FleetController extends Controller
{
    public function analysis(Request $request)
    {
        $tenant = $request->user()->tenant_id;
        $period = DashboardPeriod::fromRequest($request);
        $rows = collect(FleetCosts::perVehicle($tenant, $period));

        $money = fn ($value) => round((float) $value, 2);

        return response()->json(['data' => [
            'period' => $period->toArray(),
            'vehicles_by_status' => Vehicle::query()->where('tenant_id', $tenant)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'totals' => [
                'fuel_cost' => $money($rows->sum('fuel_cost')), 'service_cost' => $money($rows->sum('service_cost')),
                'contract_cost' => $money($rows->sum('contract_cost')), 'total_cost' => $money($rows->sum('total_cost')),
                'fuel_liters' => $money($rows->sum('fuel_liters')), 'distance' => $money($rows->sum('distance')),
            ],
            'vehicles' => $rows->sortByDesc('total_cost')->values()->take($request->integer('limit', 50))->all(),
            'alerts' => [
                'contracts_expiring_30_days' => VehicleContract::query()->where('tenant_id', $tenant)->where('status', 'active')->whereBetween('expires_on', [today()->toDateString(), today()->addDays(30)->toDateString()])->count(),
                'contracts_expired' => VehicleContract::query()->where('tenant_id', $tenant)->where('status', 'active')->whereDate('expires_on', '<', today())->count(),
                'services_due' => VehicleService::query()->where('tenant_id', $tenant)->where('status', 'planned')->whereDate('service_date', '<=', today()->addDays(14))->count(),
                'vehicles_without_driver' => Vehicle::query()->where('tenant_id', $tenant)->where('status', 'active')->whereNull('driver_employee_id')->count(),
            ],
        ]]);
    }
}

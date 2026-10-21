<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\HR\Http\Resources\HrRecordResource;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleOdometerLog;
use Modules\HR\Services\FleetService;

/**
 * Odometer readings. A reading has to fit between the ones before and after it; the newest one is the vehicle's odometer.
 */
class VehicleOdometerLogController extends Controller
{
    use Filterable, Sortable;

    public function __construct(private readonly FleetService $fleet) {}

    public function index(Request $request)
    {
        $query = VehicleOdometerLog::query()->where('tenant_id', $request->user()->tenant_id)->with(['vehicle:id,license_plate', 'employee:id,code,name'])
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->query('vehicle_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('logged_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('logged_on', '<=', $request->date('to')));

        $this->applyFilters($request, $query, ['notes', 'vehicle.license_plate']);
        $this->applySorting($request, $query);

        return HrRecordResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function store(Request $request)
    {
        $tenant = $request->user()->tenant_id;
        $data = $request->validate([
            'vehicle_id' => ['required', 'string', Rule::exists('hr_vehicles', 'id')->where('tenant_id', $tenant)],
            'logged_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'value' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'employee_id' => ['nullable', 'string', Rule::exists('hr_employees', 'id')->where('tenant_id', $tenant)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $log = $this->fleet->logOdometer(
            Vehicle::query()->where('tenant_id', $tenant)->findOrFail($data['vehicle_id']), (float) $data['value'],
            CarbonImmutable::parse($data['logged_on'] ?? now())->startOfDay(), $data['employee_id'] ?? null, $data['notes'] ?? null, $request->user(),
        );

        return (new HrRecordResource($log->refresh()))->response()->setStatusCode(201);
    }
}

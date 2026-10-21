<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Exceptions\RecordInUseException;
use Modules\HR\Http\Controllers\Concerns\CrudEndpoints;
use Modules\HR\Http\Resources\HrRecordResource;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleOdometerLog;
use Modules\HR\Services\FleetService;
use Modules\HR\Support\FleetCosts;

/**
 * The company's vehicles: who drives which, where the odometer stands, and what each costs to run.
 */
class VehicleController extends Controller
{
    use CrudEndpoints;

    public function __construct(private readonly FleetService $fleet) {}

    protected function model(): string
    {
        return Vehicle::class;
    }

    protected function entity(): string
    {
        return 'Vehicle';
    }

    protected function searchable(): array
    {
        return ['license_plate', 'vin', 'brand', 'model', 'location', 'driver.name'];
    }

    protected function with(): array
    {
        return ['driver:id,code,name'];
    }

    protected function filterKeys(): array
    {
        return ['status', 'vehicle_type', 'fuel_type', 'driver_employee_id', 'brand'];
    }

    protected function scope(Builder $query, Request $request): void
    {
        $query->withCount(['services', 'fuelLogs', 'contracts']);
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $tenant = $request->user()->tenant_id;

        return [
            'license_plate' => ['required', 'string', 'max:30', Rule::unique('hr_vehicles', 'license_plate')->where('tenant_id', $tenant)->ignore($record?->id)],
            'vin' => ['nullable', 'string', 'max:40'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'model_year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'vehicle_type' => ['sometimes', Rule::in(['car', 'van', 'truck', 'motorcycle', 'other'])],
            'fuel_type' => ['sometimes', Rule::in(['diesel', 'gasoline', 'electric', 'hybrid', 'cng', 'other'])],
            'transmission' => ['nullable', Rule::in(['manual', 'automatic'])],
            'color' => ['nullable', 'string', 'max:40'],
            'seats' => ['nullable', 'integer', 'between:1,100'],
            ...($record === null ? ['odometer' => ['sometimes', 'numeric', 'min:0', 'max:99999999']] : []),
            'acquisition_date' => ['nullable', 'date'],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'residual_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'status' => ['sometimes', Rule::in(['active', 'in_maintenance', 'out_of_service', 'sold'])],
            'fixed_asset_id' => ['nullable', 'string', $this->owned($request, 'fixed_assets')],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function afterCreate(Model $record, Request $request): void
    {
        if ((float) $record->odometer > 0) {
            VehicleOdometerLog::create([
                'tenant_id' => $record->tenant_id, 'vehicle_id' => $record->id, 'logged_on' => $record->acquisition_date ?? now()->toDateString(),
                'value' => $record->odometer, 'notes' => 'Initial reading', 'created_by' => $request->user()->id,
            ]);
        }
    }

    protected function beforeDelete(Model $record): void
    {
        if ($record->services()->exists() || $record->fuelLogs()->exists() || $record->contracts()->exists()) {
            throw new RecordInUseException('This vehicle has services, fuel logs or contracts. Mark it sold or out of service instead of deleting it.');
        }
    }

    protected function exportShape(): array
    {
        return [['Plate', 'Brand', 'Model', 'Type', 'Fuel', 'Status', 'Driver', 'Odometer'], fn (Vehicle $v) => [$v->license_plate, $v->brand, $v->model, $v->vehicle_type, $v->fuel_type, $v->status, $v->driver?->name, $v->odometer]];
    }

    protected function exportName(): string
    {
        return 'vehicles';
    }

    /**
     * Gives the vehicle to a driver (or takes it away with `employee_id: null`) from a date (POST /vehicles/{id}/assign-driver).
     */
    public function assignDriver(Request $request, string $id)
    {
        $data = $request->validate([
            'employee_id' => ['present', 'nullable', 'string', $this->owned($request, 'hr_employees')],
            'date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $vehicle = $this->fleet->assignDriver(
            $this->find($request, $id), $data['employee_id'] ? Employee::query()->findOrFail($data['employee_id']) : null,
            CarbonImmutable::parse($data['date'] ?? now())->startOfDay(), $data['notes'] ?? null, $request->user(),
        );

        return new HrRecordResource($vehicle->load($this->with()));
    }

    /**
     * Every driver the vehicle has had, newest first (GET /vehicles/{id}/assignments).
     */
    public function assignments(Request $request, string $id)
    {
        $vehicle = $this->find($request, $id);

        return HrRecordResource::collection($vehicle->assignments()->with('employee:id,code,name')->orderByDesc('starts_on')->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    /**
     * What the vehicle cost over a period, in total and per month (GET /vehicles/{id}/costs).
     */
    public function costs(Request $request, string $id)
    {
        $vehicle = $this->find($request, $id);
        $period = DashboardPeriod::fromRequest($request);

        $months = [];

        for ($month = $period->from->startOfMonth(); $month->lte($period->to); $month = $month->addMonth()) {
            $slice = new DashboardPeriod($month->max($period->from), $month->endOfMonth()->min($period->to), 'month');
            $row = FleetCosts::perVehicle($vehicle->tenant_id, $slice, $vehicle->id)[0] ?? null;
            $months[] = ['month' => $month->format('Y-m'), 'fuel_cost' => $row['fuel_cost'] ?? 0, 'service_cost' => $row['service_cost'] ?? 0, 'contract_cost' => $row['contract_cost'] ?? 0, 'total_cost' => $row['total_cost'] ?? 0];
        }

        return response()->json(['data' => ['period' => $period->toArray(), 'total' => FleetCosts::perVehicle($vehicle->tenant_id, $period, $vehicle->id)[0] ?? null, 'by_month' => $months]]);
    }
}

<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\HR\Http\Controllers\Concerns\CrudEndpoints;
use Modules\HR\Http\Resources\HrRecordResource;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleService;
use Modules\HR\Services\FleetService;

/**
 * Services and repairs of vehicles, planned and done, with their cost and when the next one falls due.
 */
class VehicleServiceController extends Controller
{
    use CrudEndpoints {
        store as protected crudStore;
    }

    public function __construct(private readonly FleetService $fleet) {}

    protected function model(): string
    {
        return VehicleService::class;
    }

    protected function entity(): string
    {
        return 'VehicleService';
    }

    protected function searchable(): array
    {
        return ['description', 'reference', 'vendor_name', 'vehicle.license_plate'];
    }

    protected function defaultOrder(): array
    {
        return ['service_date', 'desc'];
    }

    protected function with(): array
    {
        return ['vehicle:id,license_plate,brand,model', 'serviceType:id,name,category'];
    }

    protected function filterKeys(): array
    {
        return ['vehicle_id', 'status', 'service_type_id'];
    }

    protected function scope(Builder $query, Request $request): void
    {
        $query->when($request->filled('from'), fn ($q) => $q->whereDate('service_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('service_date', '<=', $request->date('to')))
            ->when($request->boolean('due'), fn ($q) => $q->where('status', 'planned')->whereDate('service_date', '<=', now()->addDays(14)));
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'vehicle_id' => ['required', 'string', $this->owned($request, 'hr_vehicles')],
            'service_type_id' => ['required', 'string', $this->owned($request, 'hr_fleet_service_types')],
            'service_date' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(['planned', 'done', 'cancelled'])],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'cost' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'supplier_id' => ['nullable', 'string', $this->owned($request, 'suppliers')],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'next_due_date' => ['nullable', 'date', 'after_or_equal:service_date'],
            'next_due_odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $response = $this->crudStore($request);
            $service = VehicleService::query()->findOrFail($response->getData(true)['data']['id']);

            if ($service->status === 'done' && $service->odometer !== null) {
                $this->fleet->logOdometer(Vehicle::query()->findOrFail($service->vehicle_id), (float) $service->odometer, $service->service_date->toImmutable(), null, 'Service: '.$service->description, $request->user());
            }

            if ($service->status === 'planned') {
                Vehicle::query()->whereKey($service->vehicle_id)->where('status', 'active')->update(['status' => 'in_maintenance']);
            }

            return $response;
        });
    }

    /**
     * Marks a planned service done with what it turned out to be (POST /vehicle-services/{id}/mark-done): the date, the cost,
     * the odometer reading and when it is next due.
     */
    public function markDone(Request $request, string $id)
    {
        $data = $request->validate([
            'service_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'cost' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'odometer' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'next_due_date' => ['sometimes', 'nullable', 'date'],
            'next_due_odometer' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        return new HrRecordResource($this->fleet->completeService($this->find($request, $id), $data, $request->user())->load($this->with()));
    }

    protected function exportShape(): array
    {
        return [['Date', 'Vehicle', 'Service', 'Status', 'Cost', 'Odometer', 'Vendor'], fn (VehicleService $s) => [$s->service_date->toDateString(), $s->vehicle?->license_plate, $s->serviceType?->name, $s->status, $s->cost, $s->odometer, $s->vendor_name]];
    }

    protected function exportName(): string
    {
        return 'vehicle-services';
    }
}

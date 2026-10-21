<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\HR\Http\Controllers\Concerns\CrudEndpoints;
use Modules\HR\Http\Resources\HrRecordResource;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleFuelLog;
use Modules\HR\Services\FleetService;

/**
 * Refuellings. Give the litres and either the price per litre or the amount; the other is worked out.
 */
class VehicleFuelLogController extends Controller
{
    use CrudEndpoints;

    public function __construct(private readonly FleetService $fleet) {}

    protected function model(): string
    {
        return VehicleFuelLog::class;
    }

    protected function entity(): string
    {
        return 'VehicleFuelLog';
    }

    protected function searchable(): array
    {
        return ['vendor_name', 'notes', 'vehicle.license_plate'];
    }

    protected function defaultOrder(): array
    {
        return ['fueled_on', 'desc'];
    }

    protected function with(): array
    {
        return ['vehicle:id,license_plate', 'employee:id,code,name'];
    }

    protected function filterKeys(): array
    {
        return ['vehicle_id', 'employee_id'];
    }

    protected function scope(Builder $query, Request $request): void
    {
        $query->when($request->filled('from'), fn ($q) => $q->whereDate('fueled_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('fueled_on', '<=', $request->date('to')));
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'vehicle_id' => ['required', 'string', $this->owned($request, 'hr_vehicles')],
            'fueled_on' => ['required', 'date', 'before_or_equal:today'],
            'liters' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'price_per_liter' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'full_tank' => ['sometimes', 'boolean'],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'string', $this->owned($request, 'hr_employees')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request, null));

        if (! isset($data['price_per_liter']) && ! isset($data['amount'])) {
            return response()->json(['message' => 'Give the price per litre or the total amount.', 'errors' => ['amount' => ['Give the price per litre or the total amount.']]], 422);
        }

        $log = $this->fleet->refuel(Vehicle::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($data['vehicle_id']), $data, $request->user());
        $this->recordAudit($request, 'hr', 'VehicleFuelLog', $log, 'INSERT', null, ['vehicle_id' => $log->vehicle_id, 'liters' => (string) $log->liters, 'amount' => (string) $log->amount]);

        return (new HrRecordResource($log->refresh()->load($this->with())))->response()->setStatusCode(201);
    }

    protected function onUpdate(array $data, Model $record, Request $request): array
    {
        $liters = (float) ($data['liters'] ?? $record->liters);
        $price = (float) ($data['price_per_liter'] ?? $record->price_per_liter);

        if (! isset($data['amount']) && (isset($data['liters']) || isset($data['price_per_liter']))) {
            $data['amount'] = round($liters * $price, 2);
        }

        return $data;
    }

    protected function exportShape(): array
    {
        return [['Date', 'Vehicle', 'Liters', 'Price/L', 'Amount', 'Odometer', 'Vendor'], fn (VehicleFuelLog $f) => [$f->fueled_on->toDateString(), $f->vehicle?->license_plate, $f->liters, $f->price_per_liter, $f->amount, $f->odometer, $f->vendor_name]];
    }

    protected function exportName(): string
    {
        return 'vehicle-fuel-logs';
    }
}

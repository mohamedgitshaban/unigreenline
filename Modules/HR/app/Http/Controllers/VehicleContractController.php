<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Http\Controllers\Concerns\CrudEndpoints;
use Modules\HR\Http\Resources\HrRecordResource;
use Modules\HR\Models\VehicleContract;

/**
 * Insurance, leasing and registration contracts of vehicles, and when they run out.
 */
class VehicleContractController extends Controller
{
    use CrudEndpoints;

    protected function model(): string
    {
        return VehicleContract::class;
    }

    protected function entity(): string
    {
        return 'VehicleContract';
    }

    protected function searchable(): array
    {
        return ['reference', 'vendor_name', 'notes', 'vehicle.license_plate'];
    }

    protected function with(): array
    {
        return ['vehicle:id,license_plate,brand,model'];
    }

    protected function filterKeys(): array
    {
        return ['vehicle_id', 'type', 'status'];
    }

    /**
     * `?expiring_within=30` lists active contracts that expire within that many days or already have.
     */
    protected function scope(Builder $query, Request $request): void
    {
        $query->when($request->filled('expiring_within'), fn ($q) => $q->where('status', 'active')->whereDate('expires_on', '<=', now()->addDays($request->integer('expiring_within'))))
            ->orderBy('expires_on');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'vehicle_id' => ['required', 'string', $this->owned($request, 'hr_vehicles')],
            'type' => ['sometimes', Rule::in(['insurance', 'leasing', 'registration', 'other'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'string', $this->owned($request, 'suppliers')],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'expires_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'cost' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'frequency' => ['sometimes', Rule::in(['once', 'monthly', 'yearly'])],
            'status' => ['sometimes', Rule::in(['active', 'closed'])],
            'reminder_days' => ['sometimes', 'integer', 'between:0,365'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Ends a contract early or marks it finished (POST /vehicle-contracts/{id}/close).
     */
    public function close(Request $request, string $id)
    {
        $contract = $this->find($request, $id);

        if ($contract->status === 'closed') {
            throw new HrRuleException('This contract is already closed.');
        }

        $this->updateRecord($request, $contract, ['status' => 'closed'], 'hr', 'VehicleContract');

        return new HrRecordResource($contract->refresh()->load($this->with()));
    }

    protected function exportShape(): array
    {
        return [['Vehicle', 'Type', 'Reference', 'Vendor', 'Starts', 'Expires', 'Cost', 'Frequency', 'Status'], fn (VehicleContract $c) => [$c->vehicle?->license_plate, $c->type, $c->reference, $c->vendor_name, $c->starts_on->toDateString(), $c->expires_on->toDateString(), $c->cost, $c->frequency, $c->status]];
    }

    protected function exportName(): string
    {
        return 'vehicle-contracts';
    }
}

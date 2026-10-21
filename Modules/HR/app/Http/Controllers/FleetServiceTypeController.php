<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HR\Http\Controllers\Concerns\CrudEndpoints;
use Modules\HR\Models\FleetServiceType;

/**
 * Kinds of service and repair. A company that has none gets a standard list the first time it looks.
 */
class FleetServiceTypeController extends Controller
{
    use CrudEndpoints {
        index as protected crudIndex;
    }

    private const DEFAULTS = ['Oil change' => 'service', 'Tyres' => 'service', 'Brakes' => 'repair', 'Battery' => 'repair', 'General inspection' => 'inspection', 'Repair' => 'repair'];

    protected function model(): string
    {
        return FleetServiceType::class;
    }

    protected function entity(): string
    {
        return 'FleetServiceType';
    }

    protected function searchable(): array
    {
        return ['name'];
    }

    protected function defaultOrder(): array
    {
        return ['name', 'asc'];
    }

    protected function filterKeys(): array
    {
        return ['category'];
    }

    public function index(Request $request)
    {
        $tenant = $request->user()->tenant_id;

        if (! FleetServiceType::query()->where('tenant_id', $tenant)->exists()) {
            foreach (self::DEFAULTS as $name => $category) {
                FleetServiceType::query()->firstOrCreate(['tenant_id' => $tenant, 'name' => $name], ['category' => $category]);
            }
        }

        return $this->crudIndex($request);
    }

    protected function rules(Request $request, $record): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('hr_fleet_service_types', 'name')->where('tenant_id', $request->user()->tenant_id)->ignore($record?->id)],
            'category' => ['sometimes', Rule::in(['service', 'repair', 'inspection', 'other'])],
        ];
    }
}

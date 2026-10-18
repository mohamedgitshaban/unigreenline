<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\HR\Http\Requests\StoreHolidayRequest;
use Modules\HR\Http\Requests\UpdateHolidayRequest;
use Modules\HR\Http\Resources\HolidayResource;
use Modules\HR\Models\Holiday;

class HolidayController extends Controller
{
    use Filterable, ManagesRecords, Sortable;

    public function index(Request $request)
    {
        $query = Holiday::query()->where('tenant_id', $request->user()->tenant_id);

        $this->applyFilters($request, $query, ['name']);
        $this->applySorting($request, $query);

        return HolidayResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, Holiday $holiday)
    {
        abort_unless($holiday->tenant_id === $request->user()->tenant_id, 404);

        return new HolidayResource($holiday);
    }

    public function store(StoreHolidayRequest $request)
    {
        $holiday = Holiday::create($request->validated() + ['tenant_id' => $request->user()->tenant_id]);

        $this->recordAudit($request, 'hr', 'Holiday', $holiday, 'INSERT', null, $holiday->only(['name']));

        return (new HolidayResource($holiday))->response()->setStatusCode(201);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday)
    {
        $this->updateRecord($request, $holiday, $request->validated(), 'hr', 'Holiday');

        return new HolidayResource($holiday);
    }

    public function destroy(Request $request, Holiday $holiday)
    {
        return $this->destroyRecord($request, $holiday, 'hr', 'Holiday');
    }
}

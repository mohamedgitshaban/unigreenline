<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\HR\Http\Requests\StoreWorkScheduleRequest;
use Modules\HR\Http\Requests\UpdateWorkScheduleRequest;
use Modules\HR\Http\Resources\WorkScheduleResource;
use Modules\HR\Models\WorkSchedule;

/**
 * Working-hours templates. Exactly one may be the company default (the one
 * used for an employee who has no schedule of their own); marking another as
 * default demotes the previous one.
 */
class WorkScheduleController extends Controller
{
    use Filterable, ManagesRecords, Sortable;

    public function index(Request $request)
    {
        $query = WorkSchedule::query()->where('tenant_id', $request->user()->tenant_id);

        $this->applyFilters($request, $query, ['name']);
        $this->applySorting($request, $query);

        return WorkScheduleResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, WorkSchedule $workSchedule)
    {
        abort_unless($workSchedule->tenant_id === $request->user()->tenant_id, 404);

        return new WorkScheduleResource($workSchedule);
    }

    public function store(StoreWorkScheduleRequest $request)
    {
        $schedule = DB::transaction(function () use ($request) {
            $data = $request->validated() + ['tenant_id' => $request->user()->tenant_id];
            $data['weekend_days'] = array_values(array_map('intval', $data['weekend_days']));

            if ($data['is_default'] ?? false) {
                $this->demoteDefault($request->user()->tenant_id);
            }

            $schedule = WorkSchedule::create($data);
            $this->recordAudit($request, 'hr', 'WorkSchedule', $schedule, 'INSERT', null, $schedule->only(['name', 'start_time', 'end_time']));

            return $schedule;
        });

        return (new WorkScheduleResource($schedule))->response()->setStatusCode(201);
    }

    public function update(UpdateWorkScheduleRequest $request, WorkSchedule $workSchedule)
    {
        abort_unless($workSchedule->tenant_id === $request->user()->tenant_id, 404);

        DB::transaction(function () use ($request, $workSchedule) {
            $data = $request->validated();

            if (isset($data['weekend_days'])) {
                $data['weekend_days'] = array_values(array_map('intval', $data['weekend_days']));
            }

            if ($data['is_default'] ?? false) {
                $this->demoteDefault($workSchedule->tenant_id, $workSchedule->id);
            }

            $this->updateRecord($request, $workSchedule, $data, 'hr', 'WorkSchedule');
        });

        return new WorkScheduleResource($workSchedule);
    }

    public function destroy(Request $request, WorkSchedule $workSchedule)
    {
        return $this->destroyRecord($request, $workSchedule, 'hr', 'WorkSchedule');
    }

    private function demoteDefault(string $tenantId, ?string $exceptId = null): void
    {
        WorkSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default' => false]);
    }
}

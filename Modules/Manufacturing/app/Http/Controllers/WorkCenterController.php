<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Http\Requests\StoreWorkCenterRequest;
use Modules\Manufacturing\Http\Requests\UpdateWorkCenterRequest;
use Modules\Manufacturing\Http\Resources\WorkCenterResource;
use Modules\Manufacturing\Models\WorkCenter;
use Modules\Manufacturing\Models\WorkCenterDowntime;
use Modules\Manufacturing\Models\WorkOrder;
use Modules\Manufacturing\Services\WorkCenterScheduler;
use Modules\Manufacturing\Services\WorkCenterService;

class WorkCenterController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    public function __construct(
        private readonly WorkCenterService $centers,
        private readonly WorkCenterScheduler $scheduler,
    ) {}

    public function index(Request $request)
    {
        $query = WorkCenter::query()->where('tenant_id', $request->user()->tenant_id)->with('calendar');
        $query->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')));

        $this->applyFilters($request, $query, ['code', 'name', 'notes']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Code', 'Name', 'Capacity', 'Efficiency %', 'Machine / hour', 'Labour / hour', 'Active'],
            fn (WorkCenter $c) => [$c->code, $c->name, $c->capacity, $c->efficiency_pct, $c->cost_per_hour, $c->labor_cost_per_hour, $c->active ? 'Yes' : 'No'],
            'work-centers',
        )) {
            return $export;
        }

        return WorkCenterResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, WorkCenter $workCenter)
    {
        $this->owned($request, $workCenter);

        return new WorkCenterResource($workCenter->load(['calendar', 'downtimes']));
    }

    public function store(StoreWorkCenterRequest $request)
    {
        $center = $this->centers->create($request->validated() + ['tenant_id' => $request->user()->tenant_id]);
        $this->recordAudit($request, 'manufacturing', 'WorkCenter', $center, 'INSERT', null, $center->only(['code', 'name', 'capacity']));

        return (new WorkCenterResource($center->load(['calendar', 'downtimes'])))->response()->setStatusCode(201);
    }

    public function update(UpdateWorkCenterRequest $request, WorkCenter $workCenter)
    {
        $this->owned($request, $workCenter);
        $this->updateRecord($request, $workCenter, $request->validated(), 'manufacturing', 'WorkCenter');

        return new WorkCenterResource($workCenter->load(['calendar', 'downtimes']));
    }

    /**
     * A work center with operations or work orders cannot be deleted (409); switch it off instead.
     */
    public function destroy(Request $request, WorkCenter $workCenter)
    {
        return $this->destroyRecord($request, $workCenter, 'manufacturing', 'WorkCenter');
    }

    /**
     * Replaces the weekly shifts the center works.
     */
    public function updateCalendar(Request $request, WorkCenter $workCenter)
    {
        $this->owned($request, $workCenter);

        $data = $request->validate([
            'shifts' => ['present', 'array', 'max:50'],
            'shifts.*.weekday' => ['required', 'integer', 'between:0,6'],
            'shifts.*.start_time' => ['required', 'date_format:H:i,H:i:s'],
            'shifts.*.end_time' => ['required', 'date_format:H:i,H:i:s', 'after:shifts.*.start_time'],
        ]);

        $center = $this->centers->replaceCalendar($workCenter, $data['shifts']);
        $this->recordAudit($request, 'manufacturing', 'WorkCenter', $center, 'UPDATE', null, ['calendar' => count($data['shifts']).' shifts']);

        return new WorkCenterResource($center->load('downtimes'));
    }

    public function downtimes(Request $request, WorkCenter $workCenter)
    {
        $this->owned($request, $workCenter);

        return response()->json(['data' => $workCenter->downtimes()->get()->map(fn ($d) => [
            'id' => $d->id, 'starts_at' => $d->starts_at, 'ends_at' => $d->ends_at, 'reason' => $d->reason,
        ])->values()]);
    }

    /**
     * Declares a period the center cannot be used (breakdown, maintenance). Work orders already booked in
     * it are not moved automatically: reschedule them (the response lists them).
     */
    public function storeDowntime(Request $request, WorkCenter $workCenter)
    {
        $this->owned($request, $workCenter);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $downtime = $workCenter->downtimes()->create($data + ['created_by' => $request->user()->id]);

        $affected = WorkOrder::query()
            ->where('work_center_id', $workCenter->id)->whereIn('status', WorkOrder::OPEN)
            ->where('scheduled_start', '<', $downtime->ends_at)->where('scheduled_end', '>', $downtime->starts_at)
            ->get(['id', 'number', 'scheduled_start', 'scheduled_end']);

        $this->recordAudit($request, 'manufacturing', 'WorkCenterDowntime', $downtime, 'INSERT', null, ['work_center' => $workCenter->code, 'from' => (string) $downtime->starts_at]);

        return response()->json(['data' => [
            'id' => $downtime->id, 'starts_at' => $downtime->starts_at, 'ends_at' => $downtime->ends_at, 'reason' => $downtime->reason,
            'work_orders_to_reschedule' => $affected->map(fn ($w) => ['id' => $w->id, 'number' => $w->number, 'scheduled_start' => $w->scheduled_start, 'scheduled_end' => $w->scheduled_end])->values(),
        ]], 201);
    }

    public function destroyDowntime(Request $request, WorkCenter $workCenter, WorkCenterDowntime $downtime)
    {
        $this->owned($request, $workCenter);
        abort_unless($downtime->work_center_id === $workCenter->id, 404);

        $downtime->delete();
        $this->recordAudit($request, 'manufacturing', 'WorkCenterDowntime', $downtime, 'DELETE', ['work_center' => $workCenter->code], null);

        return response()->noContent();
    }

    /**
     * What is booked on the center in a period and how much working time is left.
     */
    public function schedule(Request $request, WorkCenter $workCenter)
    {
        $this->owned($request, $workCenter);

        $data = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($data['from'] ?? now())->startOfDay();
        $to = Carbon::parse($data['to'] ?? $from->copy()->addDays(13))->endOfDay();

        $booked = WorkOrder::query()
            ->where('work_center_id', $workCenter->id)->whereIn('status', [...WorkOrder::OPEN, 'completed'])
            ->whereNotNull('scheduled_start')->where('scheduled_start', '<', $to)->where('scheduled_end', '>', $from)
            ->orderBy('scheduled_start')->with('productionOrder:id,number')->get();

        $available = $this->scheduler->availableMinutes($workCenter, $from, $to);
        $bookedMinutes = (int) $booked->whereIn('status', WorkOrder::OPEN)->sum('planned_minutes');

        return response()->json(['data' => [
            'work_center_id' => $workCenter->id,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'available_minutes' => $available,
            'booked_minutes' => $bookedMinutes,
            'capacity' => $workCenter->capacity,
            'work_orders' => $booked->map(fn ($w) => [
                'id' => $w->id, 'number' => $w->number, 'name' => $w->name, 'status' => $w->status,
                'production_order' => $w->productionOrder?->number,
                'scheduled_start' => $w->scheduled_start, 'scheduled_end' => $w->scheduled_end, 'planned_minutes' => $w->planned_minutes,
            ])->values(),
        ]]);
    }

    private function owned(Request $request, WorkCenter $center): void
    {
        abort_unless($center->tenant_id === $request->user()->tenant_id, 404);
    }
}

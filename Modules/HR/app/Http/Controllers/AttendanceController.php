<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Http\Controllers\Concerns\ScopesToOwnEmployee;
use Modules\HR\Http\Requests\StoreAttendanceRequest;
use Modules\HR\Http\Requests\UpdateAttendanceRequest;
use Modules\HR\Http\Resources\AttendanceResource;
use Modules\HR\Models\Attendance;
use Modules\HR\Models\Employee;
use Modules\HR\Services\AttendanceService;

class AttendanceController extends Controller
{
    use Exportable, Filterable, ManagesRecords, ScopesToOwnEmployee, Sortable;

    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request)
    {
        $query = Attendance::query()->where('tenant_id', $request->user()->tenant_id)->with('employee');

        $this->scopeToOwn($request, $query);
        $this->applyFilters($request, $query, ['employee.name', 'employee.code', 'notes']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Date', 'Employee Code', 'Employee', 'Check In', 'Check Out', 'Worked (min)', 'Late (min)', 'Early Leave (min)', 'Overtime (min)', 'Source'],
            fn (Attendance $a) => [$a->work_date->toDateString(), $a->employee?->code, $a->employee?->name, $a->check_in, $a->check_out, $a->worked_minutes, $a->late_minutes, $a->early_leave_minutes, $a->overtime_minutes, $a->source],
            'attendances',
        )) {
            return $export;
        }

        return AttendanceResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, Attendance $attendance)
    {
        abort_unless($attendance->tenant_id === $request->user()->tenant_id, 404);
        $this->assertCanSee($request, $attendance->employee_id);

        return new AttendanceResource($attendance->load('employee'));
    }

    /**
     * HR keying a day in by hand (a forgotten check-in, a visit to the site).
     */
    public function store(StoreAttendanceRequest $request)
    {
        $attendance = $this->attendance->create($request->user()->tenant_id, $request->validated(), 'manual', $this->actor($request));

        return (new AttendanceResource($attendance->load('employee')))->response()->setStatusCode(201);
    }

    public function update(UpdateAttendanceRequest $request, Attendance $attendance)
    {
        abort_unless($attendance->tenant_id === $request->user()->tenant_id, 404);

        $attendance = $this->attendance->update($attendance, $request->validated(), $this->actor($request));

        return new AttendanceResource($attendance->load('employee'));
    }

    public function destroy(Request $request, Attendance $attendance)
    {
        return $this->destroyRecord($request, $attendance, 'hr', 'Attendance');
    }

    /**
     * Self-service: the signed-in employee starts their day.
     */
    public function checkIn(Request $request)
    {
        $attendance = $this->attendance->checkIn($this->requireOwnEmployee($request), $this->actor($request));

        return (new AttendanceResource($attendance->load('employee')))->response()->setStatusCode(201);
    }

    /**
     * Self-service: the signed-in employee ends their day.
     */
    public function checkOut(Request $request)
    {
        $attendance = $this->attendance->checkOut($this->requireOwnEmployee($request), $this->actor($request));

        return new AttendanceResource($attendance->load('employee'));
    }

    /**
     * Totals for a month, per employee: days present, minutes late / left early /
     * overtime / worked, and days still without a check-out.
     */
    public function summary(Request $request)
    {
        $data = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
            'employee_id' => ['sometimes', 'string'],
        ]);

        $start = Carbon::createFromFormat('Y-m-d', ($data['month'] ?? now()->format('Y-m')).'-01')->startOfDay();

        $query = Attendance::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereBetween('work_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->when($data['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->with('employee');

        $this->scopeToOwn($request, $query);

        $rows = $query->get()->groupBy('employee_id')->map(fn ($days) => [
            'employee' => ['id' => $days->first()->employee_id, 'code' => $days->first()->employee?->code, 'name' => $days->first()->employee?->name],
            'days_present' => $days->count(),
            'incomplete_days' => $days->whereNull('worked_minutes')->count(),
            'worked_minutes' => (int) $days->sum('worked_minutes'),
            'late_minutes' => (int) $days->sum('late_minutes'),
            'early_leave_minutes' => (int) $days->sum('early_leave_minutes'),
            'overtime_minutes' => (int) $days->sum('overtime_minutes'),
        ])->sortBy('employee.name')->values();

        return response()->json(['data' => ['month' => $start->format('Y-m'), 'employees' => $rows]]);
    }

    private function requireOwnEmployee(Request $request): Employee
    {
        return $this->ownEmployee($request) ?? throw new HrRuleException('Your login is not linked to an employee record. Ask HR to link it.');
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return ['actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip()];
    }
}

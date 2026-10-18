<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\HR\Http\Requests\StoreEmployeeRequest;
use Modules\HR\Http\Requests\UpdateEmployeeRequest;
use Modules\HR\Http\Resources\EmployeeResource;
use Modules\HR\Models\Employee;
use Modules\HR\Services\LeaveService;

/**
 * An employee that has attendance, leave or payslips can't be deleted (409) —
 * give them a termination date and set them inactive instead.
 */
class EmployeeController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    private const RELATIONS = ['department', 'workSchedule'];

    public function index(Request $request)
    {
        $query = Employee::query()->where('tenant_id', $request->user()->tenant_id)->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['code', 'name', 'email', 'phone', 'job_title', 'department.name']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Code', 'Name', 'Job Title', 'Department', 'Hire Date', 'Status', 'Email', 'Phone'],
            fn (Employee $e) => [$e->code, $e->name, $e->job_title, $e->department?->name, $e->hire_date->toDateString(), $e->status, $e->email, $e->phone],
            'employees',
        )) {
            return $export;
        }

        return EmployeeResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, Employee $employee)
    {
        abort_unless($employee->tenant_id === $request->user()->tenant_id, 404);

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function store(StoreEmployeeRequest $request)
    {
        $employee = Employee::create($request->validated() + ['tenant_id' => $request->user()->tenant_id]);

        $this->recordAudit($request, 'hr', 'Employee', $employee, 'INSERT', null, $employee->only(['code', 'name', 'hire_date']));

        return (new EmployeeResource($employee->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee)
    {
        $this->updateRecord($request, $employee, $request->validated(), 'hr', 'Employee');

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function destroy(Request $request, Employee $employee)
    {
        return $this->destroyRecord($request, $employee, 'hr', 'Employee');
    }

    /**
     * Annual leave for a year: entitlement, taken (approved), pending, remaining.
     */
    public function leaveBalance(Request $request, Employee $employee, LeaveService $leave)
    {
        abort_unless($employee->tenant_id === $request->user()->tenant_id, 404);

        $year = $request->validate(['year' => ['sometimes', 'integer', 'between:2000,2100']])['year'] ?? now()->year;

        return response()->json(['data' => ['employee_id' => $employee->id, 'year' => (int) $year] + $leave->balance($employee, (int) $year)]);
    }
}

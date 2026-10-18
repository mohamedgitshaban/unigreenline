<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\HR\Http\Requests\StoreDepartmentRequest;
use Modules\HR\Http\Requests\UpdateDepartmentRequest;
use Modules\HR\Http\Resources\DepartmentResource;
use Modules\HR\Models\Department;

class DepartmentController extends Controller
{
    use Filterable, ManagesRecords, Sortable;

    public function index(Request $request)
    {
        $query = Department::query()->where('tenant_id', $request->user()->tenant_id)
            ->withCount('employees');

        $this->applyFilters($request, $query, ['name']);
        $this->applySorting($request, $query);

        return DepartmentResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, Department $department)
    {
        abort_unless($department->tenant_id === $request->user()->tenant_id, 404);

        return new DepartmentResource($department);
    }

    public function store(StoreDepartmentRequest $request)
    {
        $department = Department::create($request->validated() + ['tenant_id' => $request->user()->tenant_id]);

        $this->recordAudit($request, 'hr', 'Department', $department, 'INSERT', null, $department->only(['name']));

        return (new DepartmentResource($department))->response()->setStatusCode(201);
    }

    public function update(UpdateDepartmentRequest $request, Department $department)
    {
        $this->updateRecord($request, $department, $request->validated(), 'hr', 'Department');

        return new DepartmentResource($department);
    }

    public function destroy(Request $request, Department $department)
    {
        return $this->destroyRecord($request, $department, 'hr', 'Department');
    }
}

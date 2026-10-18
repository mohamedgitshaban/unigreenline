<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Services\AuditLogService;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Http\Controllers\Concerns\ScopesToOwnEmployee;
use Modules\HR\Http\Requests\RejectRequest;
use Modules\HR\Http\Requests\StoreTimePermissionRequest;
use Modules\HR\Http\Requests\UpdateTimePermissionRequest;
use Modules\HR\Http\Resources\TimePermissionResource;
use Modules\HR\Models\TimePermission;
use Modules\HR\Services\TimePermissionService;

/**
 * Time permissions — approved slips that excuse late arrival, early leave or a partial absence. HR sees and handles everyone's; a self-service employee raises and
 * manages their own (still pending only) and cannot approve anything.
 */
class TimePermissionController extends Controller
{
    use Filterable, ScopesToOwnEmployee, Sortable;

    private const RELATIONS = ['employee', 'decidedBy'];

    public function __construct(
        private readonly TimePermissionService $permissions,
        private readonly AuditLogService $auditLog,
    ) {}

    public function index(Request $request)
    {
        $query = TimePermission::query()->where('tenant_id', $request->user()->tenant_id)->with(self::RELATIONS);

        $this->scopeToOwn($request, $query);
        $this->applyFilters($request, $query, ['employee.name', 'employee.code', 'reason']);
        $this->applySorting($request, $query);

        return TimePermissionResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, TimePermission $timePermission)
    {
        $this->assertVisible($request, $timePermission);

        return new TimePermissionResource($timePermission->load(self::RELATIONS));
    }

    public function store(StoreTimePermissionRequest $request)
    {
        $data = $request->validated();
        $data['employee_id'] = $this->employeeIdFor($request, $data['employee_id'] ?? null);
        $data['created_by'] = $request->user()->id;

        $timePermission = $this->permissions->create($request->user()->tenant_id, $data);

        return (new TimePermissionResource($timePermission->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(UpdateTimePermissionRequest $request, TimePermission $timePermission)
    {
        $this->assertVisible($request, $timePermission);

        return new TimePermissionResource($this->permissions->update($timePermission, $request->validated(), $request->user())->load(self::RELATIONS));
    }

    public function destroy(Request $request, TimePermission $timePermission)
    {
        $this->assertVisible($request, $timePermission);

        if (! $timePermission->isPending()) {
            throw new HrRuleException('Only a pending request can be deleted.');
        }

        $timePermission->delete();

        $this->auditLog->record([
            'module' => 'hr',
            'entity_type' => 'TimePermission',
            'entity_id' => $timePermission->id,
            'operation' => 'DELETE',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $timePermission->tenant_id,
            'prev_values' => ['status' => $timePermission->status],
            'ip_address' => $request->ip(),
        ]);

        return response()->noContent();
    }

    public function approve(Request $request, TimePermission $timePermission)
    {
        $this->assertVisible($request, $timePermission);

        return new TimePermissionResource($this->permissions->decide($timePermission, 'approved', $request->user())->load(self::RELATIONS));
    }

    public function reject(RejectRequest $request, TimePermission $timePermission)
    {
        $this->assertVisible($request, $timePermission);

        return new TimePermissionResource($this->permissions->decide($timePermission, 'rejected', $request->user(), $request->validated('reason'))->load(self::RELATIONS));
    }

    private function assertVisible(Request $request, TimePermission $timePermission): void
    {
        abort_unless($timePermission->tenant_id === $request->user()->tenant_id, 404);
        $this->assertCanSee($request, $timePermission->employee_id);
    }
}

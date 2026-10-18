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
use Modules\HR\Http\Requests\StoreLeaveRequestRequest;
use Modules\HR\Http\Requests\UpdateLeaveRequestRequest;
use Modules\HR\Http\Resources\LeaveRequestResource;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Services\LeaveService;

/**
 * Leave requests. HR sees and handles everyone's; a self-service employee raises and
 * manages their own (still pending only) and cannot approve anything.
 */
class LeaveRequestController extends Controller
{
    use Filterable, ScopesToOwnEmployee, Sortable;

    private const RELATIONS = ['employee', 'decidedBy'];

    public function __construct(
        private readonly LeaveService $leave,
        private readonly AuditLogService $auditLog,
    ) {}

    public function index(Request $request)
    {
        $query = LeaveRequest::query()->where('tenant_id', $request->user()->tenant_id)->with(self::RELATIONS);

        $this->scopeToOwn($request, $query);
        $this->applyFilters($request, $query, ['employee.name', 'employee.code', 'reason']);
        $this->applySorting($request, $query);

        return LeaveRequestResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, LeaveRequest $leaveRequest)
    {
        $this->assertVisible($request, $leaveRequest);

        return new LeaveRequestResource($leaveRequest->load(self::RELATIONS));
    }

    public function store(StoreLeaveRequestRequest $request)
    {
        $data = $request->validated();
        $data['employee_id'] = $this->employeeIdFor($request, $data['employee_id'] ?? null);
        $data['created_by'] = $request->user()->id;

        $leaveRequest = $this->leave->create($request->user()->tenant_id, $data);

        return (new LeaveRequestResource($leaveRequest->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(UpdateLeaveRequestRequest $request, LeaveRequest $leaveRequest)
    {
        $this->assertVisible($request, $leaveRequest);

        return new LeaveRequestResource($this->leave->update($leaveRequest, $request->validated(), $request->user())->load(self::RELATIONS));
    }

    public function destroy(Request $request, LeaveRequest $leaveRequest)
    {
        $this->assertVisible($request, $leaveRequest);

        if (! $leaveRequest->isPending()) {
            throw new HrRuleException('Only a pending request can be deleted.');
        }

        $leaveRequest->delete();

        $this->auditLog->record([
            'module' => 'hr',
            'entity_type' => 'LeaveRequest',
            'entity_id' => $leaveRequest->id,
            'operation' => 'DELETE',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $leaveRequest->tenant_id,
            'prev_values' => ['status' => $leaveRequest->status],
            'ip_address' => $request->ip(),
        ]);

        return response()->noContent();
    }

    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        $this->assertVisible($request, $leaveRequest);

        return new LeaveRequestResource($this->leave->decide($leaveRequest, 'approved', $request->user())->load(self::RELATIONS));
    }

    public function reject(RejectRequest $request, LeaveRequest $leaveRequest)
    {
        $this->assertVisible($request, $leaveRequest);

        return new LeaveRequestResource($this->leave->decide($leaveRequest, 'rejected', $request->user(), $request->validated('reason'))->load(self::RELATIONS));
    }

    private function assertVisible(Request $request, LeaveRequest $leaveRequest): void
    {
        abort_unless($leaveRequest->tenant_id === $request->user()->tenant_id, 404);
        $this->assertCanSee($request, $leaveRequest->employee_id);
    }
}

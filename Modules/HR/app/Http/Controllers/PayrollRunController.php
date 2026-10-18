<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\HR\Http\Requests\GeneratePayrollRunRequest;
use Modules\HR\Http\Requests\PayPayrollRunRequest;
use Modules\HR\Http\Resources\PayrollRunResource;
use Modules\HR\Http\Resources\PayslipResource;
use Modules\HR\Models\PayrollRun;
use Modules\HR\Services\GeneratePayrollService;
use Modules\HR\Services\PayrollApprovalService;

/**
 * A month's payroll: calculate (once the month has ended) → review → approve
 * (posts the salaries expense) → pay (clears what is owed to employees).
 */
class PayrollRunController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = ['createdBy', 'approvedBy'];

    public function __construct(
        private readonly GeneratePayrollService $generate,
        private readonly PayrollApprovalService $approval,
    ) {}

    public function index(Request $request)
    {
        $query = PayrollRun::query()->where('tenant_id', $request->user()->tenant_id)->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['period', 'notes']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Period', 'Status', 'Employees', 'Pay Earned', 'Withheld', 'Net Pay', 'Paid At'],
            fn (PayrollRun $r) => [$r->period, $r->status, $r->employees_count, $r->total_earned, $r->total_withheld, $r->total_net, $r->paid_at?->toDateString()],
            'payroll-runs',
        )) {
            return $export;
        }

        return PayrollRunResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, PayrollRun $payrollRun)
    {
        $this->assertTenant($request, $payrollRun);

        return new PayrollRunResource($payrollRun->load(self::RELATIONS));
    }

    /**
     * The run's payslips, as their own paginated list.
     */
    public function payslips(Request $request, PayrollRun $payrollRun)
    {
        $this->assertTenant($request, $payrollRun);

        $query = $payrollRun->payslips()->getQuery()->with('payrollRun');

        $this->applyFilters($request, $query, ['employee_code', 'employee_name']);
        $this->applySorting($request, $query);

        return PayslipResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function store(GeneratePayrollRunRequest $request)
    {
        $run = $this->generate->generate($request->user()->tenant_id, $request->validated('period'), $request->validated('notes'), $this->actor($request));

        return (new PayrollRunResource($run->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function recalculate(Request $request, PayrollRun $payrollRun)
    {
        $this->assertTenant($request, $payrollRun);

        return new PayrollRunResource($this->generate->recalculate($payrollRun, $this->actor($request))->load(self::RELATIONS));
    }

    public function approve(Request $request, PayrollRun $payrollRun)
    {
        $this->assertTenant($request, $payrollRun);

        return new PayrollRunResource($this->approval->approve($payrollRun, $this->actor($request))->load(self::RELATIONS));
    }

    public function pay(PayPayrollRunRequest $request, PayrollRun $payrollRun)
    {
        $this->assertTenant($request, $payrollRun);

        $run = $this->approval->pay($payrollRun, $request->validated('payment_method'), $request->validated('payment_date'), $this->actor($request));

        return new PayrollRunResource($run->load(self::RELATIONS));
    }

    public function destroy(Request $request, PayrollRun $payrollRun)
    {
        $this->assertTenant($request, $payrollRun);

        $this->generate->delete($payrollRun, $this->actor($request));

        return response()->noContent();
    }

    private function assertTenant(Request $request, PayrollRun $run): void
    {
        abort_unless($run->tenant_id === $request->user()->tenant_id, 404);
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return ['actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip()];
    }
}

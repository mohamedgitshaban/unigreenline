<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\HR\Http\Resources\PayslipResource;
use Modules\HR\Models\Payslip;

/**
 * Payslips across all runs. Payroll staff (`payroll-runs.view`) see everyone's,
 * drafts included; anyone else sees only their own, and only once the run has
 * been approved — a draft is still being checked.
 */
class PayslipController extends Controller
{
    use Exportable, Filterable, Sortable;

    public function index(Request $request)
    {
        $query = $this->visibleTo($request)->with('payrollRun');

        $this->applyFilters($request, $query, ['employee_code', 'employee_name']);
        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Period', 'Employee Code', 'Employee', 'Gross Earnings', 'Attendance Deductions', 'Pay Earned', 'Insurance', 'Tax', 'Net Pay'],
            fn (Payslip $p) => [$p->payrollRun?->period, $p->employee_code, $p->employee_name, $p->gross_earnings, $p->attendance_deductions, $p->earned_pay, $p->insurance, $p->tax, $p->net_pay],
            'payslips',
        )) {
            return $export;
        }

        return PayslipResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, string $id)
    {
        return new PayslipResource($this->visibleTo($request)->with('payrollRun')->findOrFail($id));
    }

    /**
     * @return Builder<Payslip>
     */
    private function visibleTo(Request $request)
    {
        $user = $request->user();

        $query = Payslip::query()->whereHas('payrollRun', fn ($run) => $run->where('tenant_id', $user->tenant_id));

        if ($user->can('payroll-runs.view')) {
            return $query;
        }

        return $query
            ->whereHas('payrollRun', fn ($run) => $run->whereIn('status', ['approved', 'paid']))
            ->whereHas('employee', fn ($employee) => $employee->where('user_id', $user->id));
    }
}

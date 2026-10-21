<?php

namespace Modules\HR\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\HR\Models\Employee;

/**
 * The people: headcount, who joined and left, and how they are spread across departments.
 */
class WorkforceSection implements DashboardSection
{
    public function key(): string
    {
        return 'workforce';
    }

    public function label(): string
    {
        return 'Workforce';
    }

    public function permission(): ?string
    {
        return 'employees.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $employees = fn () => Employee::query()->where('hr_employees.tenant_id', $user->tenant_id);
        $hired = fn (DashboardPeriod $in) => $employees()->whereBetween('hire_date', [$in->from->toDateString(), $in->to->toDateString()])->count();
        $left = fn (DashboardPeriod $in) => $employees()->whereBetween('termination_date', [$in->from->toDateString(), $in->to->toDateString()])->count();

        $byDepartment = $employees()->where('hr_employees.status', 'active')->leftJoin('hr_departments', 'hr_departments.id', '=', 'hr_employees.department_id')
            ->selectRaw("coalesce(hr_departments.name, 'No department') as department, count(*) as total")->groupBy('hr_departments.name')->orderByDesc('total')->get();

        return [
            'kpis' => [
                Widgets::kpi('headcount', 'Active employees', $employees()->where('hr_employees.status', 'active')->count()),
                Widgets::kpi('hired', 'Joined', $hired($period), $hired($period->previous())),
                Widgets::kpi('left', 'Left', $left($period), $left($period->previous())),
            ],
            'charts' => [
                Widgets::chart('headcount_by_department', 'Employees per department', 'donut', $byDepartment->map(fn ($row) => ['label' => $row->department, 'value' => (int) $row->total])->all()),
            ],
        ];
    }
}

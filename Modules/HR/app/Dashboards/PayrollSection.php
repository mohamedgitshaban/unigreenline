<?php

namespace Modules\HR\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\HR\Models\PayrollRun;

/**
 * Payroll: the latest runs and what is still to approve or pay.
 */
class PayrollSection implements DashboardSection
{
    public function key(): string
    {
        return 'payroll';
    }

    public function label(): string
    {
        return 'Payroll';
    }

    public function permission(): ?string
    {
        return 'payroll-runs.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $runs = fn () => PayrollRun::query()->where('hr_payroll_runs.tenant_id', $user->tenant_id);
        $latest = $runs()->orderByDesc('period_end')->first();
        $recent = $runs()->orderByDesc('period_end')->limit(6)->get()->reverse();

        return [
            'kpis' => [
                Widgets::kpi('latest_payroll', $latest ? "Latest payroll ({$latest->period})" : 'Latest payroll', (float) ($latest?->total_net ?? 0), null, 'currency'),
                Widgets::kpi('payroll_employees', 'Paid in the latest run', (int) ($latest?->employees_count ?? 0)),
                Widgets::kpi('payroll_to_approve', 'Runs waiting for approval', $runs()->where('status', 'draft')->count()),
                Widgets::kpi('payroll_to_pay', 'Runs approved, not yet paid', $runs()->where('status', 'approved')->count()),
            ],
            'charts' => [
                Widgets::chart('payroll_history', 'Net pay of the last runs', 'bar', $recent->map(fn ($run) => ['label' => (string) $run->period, 'value' => (float) $run->total_net])->values()->all(), 'currency'),
            ],
        ];
    }
}

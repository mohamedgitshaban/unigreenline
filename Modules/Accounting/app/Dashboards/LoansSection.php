<?php

namespace Modules\Accounting\Dashboards;

use Modules\Accounting\Models\Loan;
use Modules\Accounting\Models\LoanInstallment;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;

/**
 * Loans: what the company owes and is owed, the interest on it and what falls due.
 */
class LoansSection implements DashboardSection
{
    public function key(): string
    {
        return 'loans';
    }

    public function label(): string
    {
        return 'Loans';
    }

    public function permission(): ?string
    {
        return 'loans.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $active = fn (string $kind) => Loan::query()->where('tenant_id', $user->tenant_id)->where('status', 'active')->where('kind', $kind);
        $planned = fn () => LoanInstallment::query()->where('loan_installments.tenant_id', $user->tenant_id)->where('loan_installments.status', 'planned')->whereHas('loan', fn ($q) => $q->where('status', 'active'));
        $interestPaid = fn (DashboardPeriod $in) => (float) LoanInstallment::query()->where('tenant_id', $user->tenant_id)->where('status', 'paid')
            ->whereBetween('paid_on', [$in->from->toDateString(), $in->to->toDateString()])->whereHas('loan', fn ($q) => $q->where('kind', 'borrowed'))->sum('interest_amount');

        $upcoming = $planned()->whereDate('due_date', '<=', today()->addDays(30)->toDateString())->with('loan:id,number,name,kind')->orderBy('due_date')->limit(5)->get();
        $largest = $active('borrowed')->orderByDesc('outstanding_principal')->limit(6)->get(['name', 'outstanding_principal']);

        return [
            'kpis' => [
                Widgets::kpi('loans_borrowed', 'Owed on loans', (float) $active('borrowed')->sum('outstanding_principal'), null, 'currency'),
                Widgets::kpi('loans_granted', 'Owed to us on loans', (float) $active('granted')->sum('outstanding_principal'), null, 'currency'),
                Widgets::kpi('loan_interest', 'Interest paid', $interestPaid($period), $interestPaid($period->previous()), 'currency'),
                Widgets::kpi('loan_due_30_days', 'Instalments due in 30 days', (float) $planned()->whereBetween('due_date', [today()->toDateString(), today()->addDays(30)->toDateString()])->sum('payment_amount'), null, 'currency'),
                Widgets::kpi('loan_overdue', 'Overdue instalments', $planned()->whereDate('due_date', '<', today())->count()),
            ],
            'charts' => [
                Widgets::chart('loans_by_balance', 'Largest loans owed', 'bar', $largest->map(fn ($loan) => ['label' => $loan->name, 'value' => (float) $loan->outstanding_principal])->all(), 'currency'),
            ],
            'tables' => [
                Widgets::table('upcoming_installments', 'Next instalments', [['key' => 'loan', 'label' => 'Loan'], ['key' => 'due_date', 'label' => 'Due'], ['key' => 'payment', 'label' => 'Amount'], ['key' => 'kind', 'label' => 'Direction']],
                    $upcoming->map(fn ($row) => ['id' => $row->id, 'loan' => "{$row->loan->number} {$row->loan->name}", 'due_date' => $row->due_date->toDateString(), 'payment' => (float) $row->payment_amount, 'kind' => $row->loan->kind === 'borrowed' ? 'we pay' : 'we collect'])->all()),
            ],
        ];
    }
}

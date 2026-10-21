<?php

namespace Modules\Expenses\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Expenses\Models\Expense;

/**
 * Expenses: what was spent, on what, and what is waiting to be approved.
 */
class ExpensesSection implements DashboardSection
{
    public function key(): string
    {
        return 'expenses';
    }

    public function label(): string
    {
        return 'Expenses';
    }

    public function permission(): ?string
    {
        return 'expenses.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $expenses = fn (DashboardPeriod $in) => Expense::query()->where('expenses.tenant_id', $user->tenant_id)->where('expenses.status', 'approved')
            ->whereBetween('expenses.expense_date', [$in->from->toDateString(), $in->to->toDateString()]);
        $pending = fn () => Expense::query()->where('expenses.tenant_id', $user->tenant_id)->where('expenses.status', 'draft');

        $sums = fn (DashboardPeriod $in) => $expenses($in)->selectRaw('count(*) as total, coalesce(sum(amount), 0) as amount')->first();
        $now = $sums($period);
        $before = $sums($period->previous());

        $byDay = $expenses($period)->selectRaw('expense_date as day, sum(amount) as total')->groupBy('expense_date')->pluck('total', 'day')->map(fn ($v) => (float) $v);
        $byCategory = $expenses($period)->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->selectRaw('expense_categories.name, sum(expenses.amount) as total')->groupBy('expense_categories.name')->orderByDesc('total')->limit(8)->get();

        return [
            'kpis' => [
                Widgets::kpi('expenses_total', 'Approved expenses', (float) $now->amount, (float) $before->amount, 'currency'),
                Widgets::kpi('expenses_count', 'Number of expenses', (int) $now->total, (int) $before->total),
                Widgets::kpi('expenses_pending', 'Waiting for approval', $pending()->count()),
                Widgets::kpi('expenses_pending_amount', 'Amount waiting for approval', (float) $pending()->sum('amount'), null, 'currency'),
            ],
            'charts' => [
                Widgets::chart('expenses_by_day', 'Expenses per day', 'bar', Widgets::daily($period, $byDay), 'currency'),
                Widgets::chart('expenses_by_category', 'Expenses by category', 'donut', $byCategory->map(fn ($row) => ['label' => $row->name, 'value' => (float) $row->total])->all(), 'currency'),
            ],
        ];
    }
}

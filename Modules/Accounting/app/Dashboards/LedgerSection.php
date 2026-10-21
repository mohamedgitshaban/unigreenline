<?php

namespace Modules\Accounting\Dashboards;

use Illuminate\Support\Facades\DB;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;

/**
 * The books for the period, from posted journal entries: revenue, expenses and profit, and where the money went.
 */
class LedgerSection implements DashboardSection
{
    public function key(): string
    {
        return 'ledger';
    }

    public function label(): string
    {
        return 'Revenue and expenses';
    }

    public function permission(): ?string
    {
        return 'income-statement.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $lines = fn (DashboardPeriod $in) => DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts as accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.tenant_id', $user->tenant_id)->where('journal_entries.posted', true)
            ->whereBetween('journal_entries.entry_date', [$in->from->toDateString(), $in->to->toDateString()]);

        $figures = function (DashboardPeriod $in) use ($lines) {
            $row = $lines($in)->selectRaw(
                "coalesce(sum(case when accounts.type = 'Revenue' then journal_lines.credit - journal_lines.debit end), 0) as revenue,"
                ."coalesce(sum(case when accounts.type = 'Expense' then journal_lines.debit - journal_lines.credit end), 0) as expenses"
            )->first();

            return ['revenue' => (float) $row->revenue, 'expenses' => (float) $row->expenses, 'profit' => (float) $row->revenue - (float) $row->expenses];
        };

        $now = $figures($period);
        $before = $figures($period->previous());

        $revenueByDay = $lines($period)->where('accounts.type', 'Revenue')->selectRaw('journal_entries.entry_date as day, sum(journal_lines.credit - journal_lines.debit) as total')->groupBy('journal_entries.entry_date')->pluck('total', 'day')->map(fn ($v) => (float) $v);
        $expensesByDay = $lines($period)->where('accounts.type', 'Expense')->selectRaw('journal_entries.entry_date as day, sum(journal_lines.debit - journal_lines.credit) as total')->groupBy('journal_entries.entry_date')->pluck('total', 'day')->map(fn ($v) => (float) $v);
        $topExpenses = $lines($period)->where('accounts.type', 'Expense')
            ->selectRaw('accounts.code, accounts.name, sum(journal_lines.debit - journal_lines.credit) as total')->groupBy('accounts.code', 'accounts.name')->orderByDesc('total')->limit(5)->get();

        return [
            'kpis' => [
                Widgets::kpi('revenue', 'Revenue', $now['revenue'], $before['revenue'], 'currency'),
                Widgets::kpi('expenses', 'Expenses', $now['expenses'], $before['expenses'], 'currency'),
                Widgets::kpi('profit', 'Net profit', $now['profit'], $before['profit'], 'currency'),
                Widgets::kpi('margin', 'Margin', $now['revenue'] > 0 ? round($now['profit'] / $now['revenue'] * 100, 1) : 0.0, null, 'percent'),
            ],
            'charts' => [
                Widgets::chart('revenue_by_day', 'Revenue per day', 'area', Widgets::daily($period, $revenueByDay), 'currency'),
                Widgets::chart('expenses_by_day', 'Expenses per day', 'area', Widgets::daily($period, $expensesByDay), 'currency'),
            ],
            'tables' => [
                Widgets::table('top_expense_accounts', 'Biggest expense accounts', [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Account'], ['key' => 'total', 'label' => 'Amount']],
                    $topExpenses->map(fn ($row) => ['code' => $row->code, 'name' => $row->name, 'total' => round((float) $row->total, 2)])->all()),
            ],
        ];
    }
}

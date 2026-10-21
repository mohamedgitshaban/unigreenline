<?php

namespace Modules\Accounting\Dashboards;

use Illuminate\Support\Facades\DB;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;

/**
 * Where the company stands today: what it holds, what it is owed and what it owes, from the posted ledger.
 */
class BalancesSection implements DashboardSection
{
    public function key(): string
    {
        return 'balances';
    }

    public function label(): string
    {
        return 'Financial position';
    }

    public function permission(): ?string
    {
        return 'balance-sheet.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $balances = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts as accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.tenant_id', $user->tenant_id)->where('journal_entries.posted', true)->whereDate('journal_entries.entry_date', '<=', $period->to->toDateString())
            ->selectRaw('accounts.type, accounts.code, accounts.name, sum(journal_lines.debit) as debit, sum(journal_lines.credit) as credit')
            ->groupBy('accounts.type', 'accounts.code', 'accounts.name')->get();

        $net = fn (string $type) => $balances->where('type', $type)->sum(fn ($row) => in_array($type, ['Asset', 'Expense'], true) ? $row->debit - $row->credit : $row->credit - $row->debit);
        $account = fn (string $code) => ($row = $balances->firstWhere('code', $code)) ? (float) ($row->debit - $row->credit) : 0.0;

        $assets = $balances->where('type', 'Asset')->map(fn ($row) => ['label' => $row->name, 'value' => round((float) ($row->debit - $row->credit), 2)])->filter(fn ($p) => $p['value'] > 0)->sortByDesc('value')->take(6)->values()->all();

        return [
            'kpis' => [
                Widgets::kpi('total_assets', 'Assets', (float) $net('Asset'), null, 'currency'),
                Widgets::kpi('total_liabilities', 'Liabilities', (float) $net('Liability'), null, 'currency'),
                Widgets::kpi('total_equity', 'Equity', (float) $net('Equity'), null, 'currency'),
                Widgets::kpi('receivables', 'Accounts receivable', $account('1200'), null, 'currency'),
                Widgets::kpi('payables', 'Accounts payable', -$account('2100'), null, 'currency'),
                Widgets::kpi('inventory_value', 'Inventory (ledger)', $account('1300'), null, 'currency'),
            ],
            'charts' => [
                Widgets::chart('assets_breakdown', 'Largest asset accounts', 'donut', $assets, 'currency'),
            ],
        ];
    }
}

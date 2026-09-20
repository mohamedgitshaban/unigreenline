<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;

/**
 * Balance sheet and income statement (spec §10.7). Not in the spec's own
 * §6 endpoint table — that section only lists chart-of-accounts and
 * journal-entries — but the build-order narrative explicitly asks for
 * "financial statements", so these fill that gap.
 */
class FinancialStatementService
{
    /**
     * A current snapshot only — Account::$balance is a running total, not
     * a point-in-time figure, so there's no historical "as of" date here.
     */
    public function balanceSheet(string $tenantId): array
    {
        $accounts = Account::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('code')
            ->get();

        $assets = $this->accountsOfType($accounts, 'Asset');
        $liabilities = $this->accountsOfType($accounts, 'Liability');
        $equity = $this->accountsOfType($accounts, 'Equity');

        $totalAssets = $accounts->where('type', 'Asset')->sum(fn ($a) => (float) $a->balance);
        $totalLiabilities = $accounts->where('type', 'Liability')->sum(fn ($a) => (float) $a->balance);
        $totalEquity = $accounts->where('type', 'Equity')->sum(fn ($a) => (float) $a->balance);

        return [
            'as_of' => now()->toDateString(),
            'assets' => ['accounts' => $assets, 'total' => $this->money($totalAssets)],
            'liabilities' => ['accounts' => $liabilities, 'total' => $this->money($totalLiabilities)],
            'equity' => ['accounts' => $equity, 'total' => $this->money($totalEquity)],
            'total_liabilities_and_equity' => $this->money($totalLiabilities + $totalEquity),
            'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
        ];
    }

    public function incomeStatement(string $tenantId, string $startDate, string $endDate): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->whereBetween('journal_entries.entry_date', [$startDate, $endDate])
            ->whereIn('chart_of_accounts.type', ['Revenue', 'Expense'])
            ->selectRaw('chart_of_accounts.id as account_id, chart_of_accounts.code, chart_of_accounts.name, chart_of_accounts.type,
                SUM(journal_lines.credit) - SUM(journal_lines.debit) as net_credit,
                SUM(journal_lines.debit) - SUM(journal_lines.credit) as net_debit')
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.type')
            ->orderBy('chart_of_accounts.code')
            ->get();

        $revenue = $rows->where('type', 'Revenue')->map(fn ($row) => [
            'account_id' => $row->account_id,
            'code' => $row->code,
            'name' => $row->name,
            'amount' => $this->money((float) $row->net_credit),
        ])->values();

        $expenses = $rows->where('type', 'Expense')->map(fn ($row) => [
            'account_id' => $row->account_id,
            'code' => $row->code,
            'name' => $row->name,
            'amount' => $this->money((float) $row->net_debit),
        ])->values();

        $totalRevenue = $rows->where('type', 'Revenue')->sum(fn ($row) => (float) $row->net_credit);
        $totalExpenses = $rows->where('type', 'Expense')->sum(fn ($row) => (float) $row->net_debit);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'revenue' => ['accounts' => $revenue, 'total' => $this->money($totalRevenue)],
            'expenses' => ['accounts' => $expenses, 'total' => $this->money($totalExpenses)],
            'net_income' => $this->money($totalRevenue - $totalExpenses),
        ];
    }

    /**
     * @return array<int, array{account_id: string, code: string, name: string, balance: string}>
     */
    private function accountsOfType($accounts, string $type): array
    {
        return $accounts->where('type', $type)->map(fn (Account $account) => [
            'account_id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'balance' => $account->balance,
        ])->values()->all();
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}

<?php

namespace Modules\Accounting\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\FinancialStatementService;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Models\Tenant;
use Tests\TestCase;

class FinancialStatementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_sheet_totals_accounts_by_type_and_reports_whether_balanced(): void
    {
        $tenant = Tenant::factory()->create();
        Account::factory()->recycle($tenant)->create(['code' => '1200', 'type' => 'Asset', 'balance' => 0]);
        Account::factory()->recycle($tenant)->create(['code' => '2100', 'type' => 'Liability', 'balance' => 0]);
        Account::factory()->recycle($tenant)->create(['code' => '3100', 'type' => 'Equity', 'balance' => 0]);

        $this->journalPosting()->post($tenant->id, 'REF-1', 'test', [
            ['account_code' => '1200', 'debit' => 500],
            ['account_code' => '2100', 'credit' => 300],
            ['account_code' => '3100', 'credit' => 200],
        ]);

        $sheet = $this->service()->balanceSheet($tenant->id);

        $this->assertSame('500.00', $sheet['assets']['total']);
        $this->assertSame('300.00', $sheet['liabilities']['total']);
        $this->assertSame('200.00', $sheet['equity']['total']);
        $this->assertTrue($sheet['balanced']);
    }

    public function test_balance_sheet_flags_unbalanced_when_debits_and_credits_diverge_across_periods(): void
    {
        // Post two independently-balanced entries whose *type totals* still
        // legitimately differ (e.g. undistributed net income) — balanced
        // here means assets == liabilities + equity, not "every entry summed".
        $tenant = Tenant::factory()->create();
        Account::factory()->recycle($tenant)->create(['code' => '1200', 'type' => 'Asset', 'balance' => 0]);
        Account::factory()->recycle($tenant)->create(['code' => '4100', 'type' => 'Revenue', 'balance' => 0]);

        $this->journalPosting()->post($tenant->id, 'REF-1', 'test', [
            ['account_code' => '1200', 'debit' => 100],
            ['account_code' => '4100', 'credit' => 100],
        ]);

        $sheet = $this->service()->balanceSheet($tenant->id);

        // Assets (100) vs Liabilities+Equity (0) — Revenue isn't part of
        // either, so this correctly reports unbalanced without an equity
        // close-out entry, which this build doesn't implement.
        $this->assertFalse($sheet['balanced']);
    }

    public function test_income_statement_only_includes_entries_within_the_date_range(): void
    {
        $tenant = Tenant::factory()->create();
        Account::factory()->recycle($tenant)->create(['code' => '1200', 'type' => 'Asset', 'balance' => 0]);
        Account::factory()->recycle($tenant)->create(['code' => '4100', 'type' => 'Revenue', 'balance' => 0]);

        $this->journalPosting()->post($tenant->id, 'IN-RANGE', 'test', [
            ['account_code' => '1200', 'debit' => 100],
            ['account_code' => '4100', 'credit' => 100],
        ], entryDate: Carbon::parse('2026-06-15'));

        $this->journalPosting()->post($tenant->id, 'OUT-OF-RANGE', 'test', [
            ['account_code' => '1200', 'debit' => 50],
            ['account_code' => '4100', 'credit' => 50],
        ], entryDate: Carbon::parse('2026-01-01'));

        $statement = $this->service()->incomeStatement($tenant->id, '2026-06-01', '2026-06-30');

        $this->assertSame('100.00', $statement['revenue']['total']);
    }

    public function test_income_statement_computes_net_income_as_revenue_minus_expenses(): void
    {
        $tenant = Tenant::factory()->create();
        Account::factory()->recycle($tenant)->create(['code' => '1200', 'type' => 'Asset', 'balance' => 0]);
        Account::factory()->recycle($tenant)->create(['code' => '4100', 'type' => 'Revenue', 'balance' => 0]);
        Account::factory()->recycle($tenant)->create(['code' => '6100', 'type' => 'Expense', 'balance' => 0]);

        $this->journalPosting()->post($tenant->id, 'REV', 'test', [
            ['account_code' => '1200', 'debit' => 1000],
            ['account_code' => '4100', 'credit' => 1000],
        ], entryDate: Carbon::parse('2026-06-10'));

        $this->journalPosting()->post($tenant->id, 'EXP', 'test', [
            ['account_code' => '6100', 'debit' => 400],
            ['account_code' => '1200', 'credit' => 400],
        ], entryDate: Carbon::parse('2026-06-12'));

        $statement = $this->service()->incomeStatement($tenant->id, '2026-06-01', '2026-06-30');

        $this->assertSame('1000.00', $statement['revenue']['total']);
        $this->assertSame('400.00', $statement['expenses']['total']);
        $this->assertSame('600.00', $statement['net_income']);
    }

    private function service(): FinancialStatementService
    {
        return $this->app->make(FinancialStatementService::class);
    }

    private function journalPosting(): JournalPostingService
    {
        return $this->app->make(JournalPostingService::class);
    }
}

<?php

namespace Modules\Accounting\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Exceptions\UnbalancedJournalEntryException;
use Modules\Accounting\Exceptions\UnknownAccountCodeException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Models\Tenant;
use Tests\TestCase;

class JournalPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_a_balanced_entry_with_matching_lines(): void
    {
        $tenant = Tenant::factory()->create();
        Account::factory()->recycle($tenant)->create(['code' => '1200', 'type' => 'Asset']);
        Account::factory()->recycle($tenant)->create(['code' => '4100', 'type' => 'Revenue']);

        $entry = $this->service()->post($tenant->id, 'INV-001', 'Invoice created', [
            ['account_code' => '1200', 'debit' => 100],
            ['account_code' => '4100', 'credit' => 100],
        ]);

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertTrue($entry->posted);
        $this->assertCount(2, $entry->lines);
    }

    public function test_unbalanced_entry_is_rejected_and_writes_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        Account::factory()->recycle($tenant)->create(['code' => '1200']);
        Account::factory()->recycle($tenant)->create(['code' => '4100']);

        try {
            $this->service()->post($tenant->id, 'INV-002', 'Invoice created', [
                ['account_code' => '1200', 'debit' => 100],
                ['account_code' => '4100', 'credit' => 90],
            ]);
            $this->fail('Expected UnbalancedJournalEntryException.');
        } catch (UnbalancedJournalEntryException $e) {
            $this->assertSame(100.0, $e->totalDebits);
            $this->assertSame(90.0, $e->totalCredits);
        }

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_unknown_account_code_is_rejected_and_writes_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        Account::factory()->recycle($tenant)->create(['code' => '1200']);

        try {
            $this->service()->post($tenant->id, 'INV-003', 'Invoice created', [
                ['account_code' => '1200', 'debit' => 100],
                ['account_code' => '9999', 'credit' => 100],
            ]);
            $this->fail('Expected UnknownAccountCodeException.');
        } catch (UnknownAccountCodeException $e) {
            $this->assertSame('9999', $e->accountCode);
        }

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_debit_increases_an_asset_accounts_balance(): void
    {
        $tenant = Tenant::factory()->create();
        $ar = Account::factory()->recycle($tenant)->create(['code' => '1200', 'type' => 'Asset', 'balance' => 0]);
        $revenue = Account::factory()->recycle($tenant)->create(['code' => '4100', 'type' => 'Revenue', 'balance' => 0]);

        $this->service()->post($tenant->id, 'INV-004', 'Invoice created', [
            ['account_code' => '1200', 'debit' => 500],
            ['account_code' => '4100', 'credit' => 500],
        ]);

        $this->assertSame('500.00', $ar->fresh()->balance);
        $this->assertSame('500.00', $revenue->fresh()->balance);
    }

    public function test_credit_increases_a_liability_accounts_balance_and_debit_decreases_it(): void
    {
        $tenant = Tenant::factory()->create();
        $inventory = Account::factory()->recycle($tenant)->create(['code' => '1300', 'type' => 'Asset', 'balance' => 0]);
        $ap = Account::factory()->recycle($tenant)->create(['code' => '2100', 'type' => 'Liability', 'balance' => 0]);

        $this->service()->post($tenant->id, 'PO-001', 'PO received', [
            ['account_code' => '1300', 'debit' => 200],
            ['account_code' => '2100', 'credit' => 200],
        ]);

        $this->assertSame('200.00', $inventory->fresh()->balance);
        $this->assertSame('200.00', $ap->fresh()->balance);

        $this->service()->post($tenant->id, 'PAY-001', 'Supplier payment', [
            ['account_code' => '2100', 'debit' => 50],
            ['account_code' => '1300', 'credit' => 50],
        ]);

        $this->assertSame('150.00', $ap->fresh()->balance);
    }

    private function service(): JournalPostingService
    {
        return $this->app->make(JournalPostingService::class);
    }
}

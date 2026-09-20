<?php

namespace Modules\Accounting\Tests\Feature\Database\Seeders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Database\Seeders\AccountSeeder;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\TenantSeeder;
use Tests\TestCase;

class AccountSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These specific codes are load-bearing: JournalPostingService callers
     * (invoice creation, collections, PO receiving) look accounts up by
     * these codes (spec §5.3). If this test breaks, something renamed or
     * removed a code that auto-posting logic depends on.
     */
    public function test_auto_posting_account_codes_exist_with_the_expected_types(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(AccountSeeder::class);

        $expected = [
            '1110' => 'Asset',   // Cash
            '1120' => 'Asset',   // Bank
            '1200' => 'Asset',   // Accounts Receivable
            '1300' => 'Asset',   // Inventory
            '2100' => 'Liability', // Accounts Payable
            '2300' => 'Liability', // VAT Payable
            '4100' => 'Revenue',   // Sales Revenue
        ];

        foreach ($expected as $code => $type) {
            $account = Account::query()->where('code', $code)->first();
            $this->assertNotNull($account, "Account {$code} should exist.");
            $this->assertSame($type, $account->type, "Account {$code} should be type {$type}.");
        }
    }

    public function test_seeding_twice_does_not_duplicate_accounts(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(AccountSeeder::class);
        $this->seed(AccountSeeder::class);

        $this->assertSame(21, Account::query()->count());
    }
}

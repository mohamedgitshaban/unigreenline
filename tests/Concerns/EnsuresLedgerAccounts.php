<?php

namespace Tests\Concerns;

use Modules\Accounting\Database\Seeders\AccountSeeder;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;

/**
 * Business flows post to the chart of accounts by code and refuse to run if a
 * code is missing, so a test that drives one (a sale, a receipt, a return,
 * payroll...) needs the system accounts to exist. This adds any that are not
 * there yet — never touching ones the test created itself, with their own
 * opening balances.
 */
trait EnsuresLedgerAccounts
{
    protected function ensureLedgerAccounts(Tenant $tenant): void
    {
        foreach (AccountSeeder::ACCOUNTS as $account) {
            Account::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $account['code']],
                ['name' => $account['name'], 'type' => $account['type'], 'level' => $account['level'], 'active' => true],
            );
        }
    }
}

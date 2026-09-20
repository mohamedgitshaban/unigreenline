<?php

namespace Modules\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;

/**
 * Standard Asset/Liability/Equity/Revenue/Expense hierarchy, codes 1000–6200
 * (spec §7). The exact codes the auto-posting logic in §5.3 depends on
 * (1110, 1120, 1200, 1300, 2100, 2300, 4100) are seeded here and must not
 * change without updating every caller of JournalPostingService::post().
 *
 * The prototype's exact seed figures beyond those named codes weren't
 * available to port — the surrounding hierarchy below is a reasonable
 * standard chart, not a byte-for-byte copy.
 */
class AccountSeeder extends Seeder
{
    /** @var array<int, array{code: string, name: string, type: string, level: int, parent: ?string}> */
    private const ACCOUNTS = [
        ['code' => '1000', 'name' => 'Assets', 'type' => 'Asset', 'level' => 1, 'parent' => null],
        ['code' => '1100', 'name' => 'Current Assets', 'type' => 'Asset', 'level' => 2, 'parent' => '1000'],
        ['code' => '1110', 'name' => 'Cash', 'type' => 'Asset', 'level' => 3, 'parent' => '1100'],
        ['code' => '1120', 'name' => 'Bank', 'type' => 'Asset', 'level' => 3, 'parent' => '1100'],
        ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'Asset', 'level' => 3, 'parent' => '1100'],
        ['code' => '1300', 'name' => 'Inventory', 'type' => 'Asset', 'level' => 3, 'parent' => '1100'],
        ['code' => '1500', 'name' => 'Fixed Assets', 'type' => 'Asset', 'level' => 2, 'parent' => '1000'],
        ['code' => '1510', 'name' => 'Equipment', 'type' => 'Asset', 'level' => 3, 'parent' => '1500'],
        ['code' => '1520', 'name' => 'Vehicles', 'type' => 'Asset', 'level' => 3, 'parent' => '1500'],

        ['code' => '2000', 'name' => 'Liabilities', 'type' => 'Liability', 'level' => 1, 'parent' => null],
        ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'Liability', 'level' => 2, 'parent' => '2000'],
        ['code' => '2300', 'name' => 'VAT Payable', 'type' => 'Liability', 'level' => 2, 'parent' => '2000'],

        ['code' => '3000', 'name' => 'Equity', 'type' => 'Equity', 'level' => 1, 'parent' => null],
        ['code' => '3100', 'name' => "Owner's Capital", 'type' => 'Equity', 'level' => 2, 'parent' => '3000'],
        ['code' => '3200', 'name' => 'Retained Earnings', 'type' => 'Equity', 'level' => 2, 'parent' => '3000'],

        ['code' => '4000', 'name' => 'Revenue', 'type' => 'Revenue', 'level' => 1, 'parent' => null],
        ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'Revenue', 'level' => 2, 'parent' => '4000'],
        ['code' => '4200', 'name' => 'Other Income', 'type' => 'Revenue', 'level' => 2, 'parent' => '4000'],

        ['code' => '6000', 'name' => 'Operating Expenses', 'type' => 'Expense', 'level' => 1, 'parent' => null],
        ['code' => '6100', 'name' => 'Salaries Expense', 'type' => 'Expense', 'level' => 2, 'parent' => '6000'],
        ['code' => '6200', 'name' => 'General & Administrative Expense', 'type' => 'Expense', 'level' => 2, 'parent' => '6000'],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'vetpharma')->firstOrFail();
        $idsByCode = [];

        foreach (self::ACCOUNTS as $data) {
            $account = Account::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $data['code']],
                [
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'level' => $data['level'],
                    'parent_id' => $data['parent'] ? $idsByCode[$data['parent']] : null,
                    'active' => true,
                ]
            );

            $idsByCode[$data['code']] = $account->id;
        }
    }
}

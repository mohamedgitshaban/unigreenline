<?php

namespace Modules\Expenses\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;
use Modules\Expenses\Models\ExpenseCategory;

/**
 * Starter categories mapped onto AccountSeeder's Expense accounts — run it
 * after AccountingDatabaseSeeder.
 */
class ExpenseCategorySeeder extends Seeder
{
    /** @var array<string, string> Category name => chart-of-accounts code. */
    private const CATEGORIES = [
        'Salaries & Wages' => '6100',
        'Rent' => '6200',
        'Utilities' => '6200',
        'Fuel & Transport' => '6200',
        'Maintenance & Repairs' => '6200',
        'Office Supplies' => '6200',
        'Marketing' => '6200',
        'Government Fees & Licenses' => '6200',
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'vetpharma')->firstOrFail();

        foreach (self::CATEGORIES as $name => $code) {
            $account = Account::query()->where('tenant_id', $tenant->id)->where('code', $code)->firstOrFail();

            ExpenseCategory::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                ['account_id' => $account->id, 'active' => true],
            );
        }
    }
}

<?php

namespace Modules\Accounting\Tests\Feature\Http;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class AccountingExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_export_chart_of_accounts_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $account = Account::factory()->recycle($tenant)->create(['code' => '9999', 'name' => 'Export Test Account']);

        $this->get('/api/v1/chart-of-accounts?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/chart-of-accounts-.*\.csv/', function (GenericExport $export) use ($account) {
            $this->assertSame($account->id, $export->collection()->first()[0]);
            $this->assertSame('9999', $export->collection()->first()[1]);

            return true;
        });
    }

    public function test_sales_manager_without_accounting_permissions_is_forbidden(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $this->get('/api/v1/chart-of-accounts?export=csv')->assertForbidden();
    }

    public function test_accountant_can_export_journal_entries_with_line_summary_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        Account::factory()->recycle($tenant)->create(['code' => '1200']);
        Account::factory()->recycle($tenant)->create(['code' => '4100']);
        $entry = $this->app->make(JournalPostingService::class)->post(
            $tenant->id, 'INV-EXPORT-1', 'Export test entry',
            [['account_code' => '1200', 'debit' => 100], ['account_code' => '4100', 'credit' => 100]],
        );

        $this->get('/api/v1/journal-entries?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/journal-entries-.*\.csv/', function (GenericExport $export) use ($entry) {
            $row = $export->collection()->first();
            $this->assertSame($entry->id, $row[0]);
            $this->assertStringContainsString('1200 Dr 100', $row[5]);
            $this->assertStringContainsString('4100 Cr 100', $row[5]);

            return true;
        });
    }

    public function test_sales_manager_without_accounting_permissions_cannot_export_journal_entries(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $this->get('/api/v1/journal-entries?export=csv')->assertForbidden();
    }
}

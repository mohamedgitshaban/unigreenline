<?php

namespace Modules\Accounting\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Database\Seeders\AccountSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Database\Seeders\TenantSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class AccountingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_lists_the_chart_of_accounts(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(TenantSeeder::class);
        $this->seed(AccountSeeder::class);
        $tenant = Tenant::query()->where('slug', 'vetpharma')->firstOrFail();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/chart-of-accounts?per_page=100');

        $response->assertOk();
        $this->assertCount(21, $response->json('data'));
    }

    public function test_sales_rep_cannot_list_the_chart_of_accounts(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Rep');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/chart-of-accounts');

        $response->assertForbidden();
    }

    public function test_journal_entries_endpoint_returns_nested_lines(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        Account::factory()->recycle($tenant)->create(['code' => '1200', 'type' => 'Asset']);
        Account::factory()->recycle($tenant)->create(['code' => '4100', 'type' => 'Revenue']);
        $this->app->make(JournalPostingService::class)->post($tenant->id, 'REF-1', 'test entry', [
            ['account_code' => '1200', 'debit' => 100],
            ['account_code' => '4100', 'credit' => 100],
        ]);

        $response = $this->getJson('/api/v1/journal-entries');

        $response->assertOk();
        $response->assertJsonPath('data.0.lines.0.account_code', '1200');
    }

    public function test_balance_sheet_endpoint_returns_a_snapshot(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/balance-sheet');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['as_of', 'assets', 'liabilities', 'equity', 'balanced']]);
    }

    public function test_income_statement_requires_a_date_range(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/income-statement');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['start_date', 'end_date']);
    }
}

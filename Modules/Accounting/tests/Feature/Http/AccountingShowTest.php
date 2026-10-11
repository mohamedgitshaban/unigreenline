<?php

namespace Modules\Accounting\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class AccountingShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_view_an_account(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $root = Account::factory()->recycle($tenant)->create(['code' => '1000', 'level' => 1]);
        $group = Account::factory()->recycle($tenant)->create(['code' => '1100', 'level' => 2, 'parent_id' => $root->id]);
        $account = Account::factory()->recycle($tenant)->create(['code' => '1110', 'level' => 3, 'parent_id' => $group->id]);

        $response = $this->getJson("/api/v1/chart-of-accounts/{$account->id}");

        $response->assertOk();
        $response->assertJsonPath('data.code', '1110');
        $response->assertJsonPath('data.parent.code', '1100');
        $response->assertJsonPath('data.parent.parent.code', '1000');
    }

    public function test_accountant_can_view_a_journal_entry_and_page_through_its_lines(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $assets = Account::factory()->recycle($tenant)->create(['code' => '1000', 'type' => 'Asset', 'level' => 1]);
        $currentAssets = Account::factory()->recycle($tenant)->create(['code' => '1100', 'type' => 'Asset', 'level' => 2, 'parent_id' => $assets->id]);
        Account::factory()->recycle($tenant)->create(['code' => '1200', 'type' => 'Asset', 'level' => 3, 'parent_id' => $currentAssets->id]);
        Account::factory()->recycle($tenant)->create(['code' => '4100', 'type' => 'Revenue']);
        $entry = $this->app->make(JournalPostingService::class)->post($tenant->id, 'REF-1', 'test entry', [
            ['account_code' => '1200', 'debit' => 100],
            ['account_code' => '4100', 'credit' => 100],
        ]);

        $response = $this->getJson("/api/v1/journal-entries/{$entry->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $entry->id);
        $response->assertJsonPath('data.lines_count', 2);

        $lines = $this->getJson("/api/v1/journal-entries/{$entry->id}/lines?per_page=1&sort_by=debit&sort_dir=desc");

        $lines->assertOk();
        $lines->assertJsonCount(1, 'data');
        $lines->assertJsonPath('meta.total', 2);
        $lines->assertJsonPath('data.0.account_code', '1200');
        $lines->assertJsonPath('data.0.account.parent.parent.code', '1000');
    }

    public function test_an_account_from_another_tenant_is_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $account = Account::factory()->create();

        $response = $this->getJson("/api/v1/chart-of-accounts/{$account->id}");

        $response->assertNotFound();
    }

    public function test_a_journal_entry_from_another_tenant_is_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $otherTenant = Tenant::factory()->create();
        Account::factory()->recycle($otherTenant)->create(['code' => '1200', 'type' => 'Asset']);
        Account::factory()->recycle($otherTenant)->create(['code' => '4100', 'type' => 'Revenue']);
        $entry = $this->app->make(JournalPostingService::class)->post($otherTenant->id, 'REF-1', 'test entry', [
            ['account_code' => '1200', 'debit' => 100],
            ['account_code' => '4100', 'credit' => 100],
        ]);

        $response = $this->getJson("/api/v1/journal-entries/{$entry->id}");

        $response->assertNotFound();
    }

    public function test_sales_manager_cannot_view_an_account(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $account = Account::factory()->recycle($tenant)->create();

        $response = $this->getJson("/api/v1/chart-of-accounts/{$account->id}");

        $response->assertForbidden();
    }
}

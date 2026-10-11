<?php

namespace Modules\Accounting\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalLine;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_creates_a_child_account_one_level_below_its_parent(): void
    {
        $this->actingAsRole('Administrator');
        $parent = Account::factory()->recycle($this->tenant)->create(['code' => '6000', 'level' => 1, 'type' => 'Expense']);

        $this->postJson('/api/v1/chart-of-accounts', ['code' => '6500', 'name' => 'Fuel', 'type' => 'Expense', 'parent_id' => $parent->id])
            ->assertCreated()
            ->assertJsonPath('data.level', 2)
            ->assertJsonPath('data.parent.code', '6000');

        $this->postJson('/api/v1/chart-of-accounts', ['code' => '6500', 'name' => 'Dup', 'type' => 'Expense'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_renames_and_deactivates_an_account(): void
    {
        $this->actingAsRole('Accountant');
        $account = Account::factory()->recycle($this->tenant)->create(['code' => '6500', 'type' => 'Expense']);

        $this->putJson("/api/v1/chart-of-accounts/{$account->id}", ['name' => 'Vehicle fuel', 'active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Vehicle fuel')
            ->assertJsonPath('data.active', false);
    }

    public function test_code_and_type_freeze_for_system_accounts_and_accounts_with_postings(): void
    {
        $this->actingAsRole('Administrator');
        $system = Account::factory()->recycle($this->tenant)->create(['code' => '1300', 'type' => 'Asset']);
        $used = Account::factory()->recycle($this->tenant)->create(['code' => '6500', 'type' => 'Expense']);
        $entry = JournalEntry::create(['tenant_id' => $this->tenant->id, 'ref' => 'x', 'description' => 'x', 'entry_date' => now(), 'posted' => true]);
        $entry->lines()->create(['account_id' => $used->id, 'account_code' => '6500', 'account_name' => 'x', 'debit' => 1, 'credit' => 0]);

        $this->putJson("/api/v1/chart-of-accounts/{$system->id}", ['code' => '1301'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson("/api/v1/chart-of-accounts/{$used->id}", ['type' => 'Asset'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->putJson("/api/v1/chart-of-accounts/{$used->id}", ['name' => 'Renamed'])->assertOk();
    }

    public function test_only_unused_non_system_leaf_accounts_can_be_deleted(): void
    {
        $this->actingAsRole('Administrator');
        $free = Account::factory()->recycle($this->tenant)->create(['code' => '6500', 'type' => 'Expense']);
        $system = Account::factory()->recycle($this->tenant)->create(['code' => '1300', 'type' => 'Asset']);
        $parent = Account::factory()->recycle($this->tenant)->create(['code' => '6000', 'type' => 'Expense']);
        Account::factory()->recycle($this->tenant)->create(['code' => '6100', 'type' => 'Expense', 'parent_id' => $parent->id]);
        $used = Account::factory()->recycle($this->tenant)->create(['code' => '6600', 'type' => 'Expense']);
        $entry = JournalEntry::create(['tenant_id' => $this->tenant->id, 'ref' => 'x', 'description' => 'x', 'entry_date' => now(), 'posted' => true]);
        $entry->lines()->create(['account_id' => $used->id, 'account_code' => '6600', 'account_name' => 'x', 'debit' => 1, 'credit' => 0]);

        $this->deleteJson("/api/v1/chart-of-accounts/{$free->id}")->assertNoContent();
        $this->deleteJson("/api/v1/chart-of-accounts/{$system->id}")->assertConflict();
        $this->deleteJson("/api/v1/chart-of-accounts/{$parent->id}")->assertConflict();
        $this->deleteJson("/api/v1/chart-of-accounts/{$used->id}")->assertConflict();

        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $free->id]);
        $this->assertSame(1, JournalLine::query()->count());
    }

    public function test_writes_follow_accounting_permissions_and_tenant(): void
    {
        $account = Account::factory()->recycle($this->tenant)->create(['code' => '6500', 'type' => 'Expense']);

        $this->actingAsRole('Accountant');
        $this->deleteJson("/api/v1/chart-of-accounts/{$account->id}")->assertForbidden();
        $this->postJson('/api/v1/chart-of-accounts', ['code' => '6700', 'name' => 'Ok', 'type' => 'Expense'])->assertCreated();

        $this->actingAsRole('Auditor');
        $this->putJson("/api/v1/chart-of-accounts/{$account->id}", ['name' => 'No'])->assertForbidden();

        $this->actingAsRole('Administrator');
        $this->deleteJson('/api/v1/chart-of-accounts/'.Account::factory()->create()->id)->assertNotFound();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}

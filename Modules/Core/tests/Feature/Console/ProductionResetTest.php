<?php

namespace Modules\Core\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionCatalogue;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\Unit;
use Modules\Purchasing\Models\Supplier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaves_one_account_with_one_role_holding_every_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $salesRep = $this->userWithRole($tenant, 'Sales Rep');
        $this->userWithRole($tenant, 'Accountant');
        $customer = Customer::factory()->recycle($tenant)->create();
        $supplier = Supplier::factory()->recycle($tenant)->create();
        $salesRep->createToken('rep-token');

        $this->artisan('production:reset', ['--email' => 'owner@example.com', '--name' => 'Go Live', '--password' => 'a-strong-password', '--force' => true])
            ->assertSuccessful();

        $this->assertModelMissing($customer);
        $this->assertModelMissing($supplier);
        $this->assertModelExists($tenant);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('roles', 1);

        $user = User::query()->firstOrFail();
        $this->assertSame('owner@example.com', $user->email);
        $this->assertSame('Go Live', $user->name);
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertTrue(Hash::check('a-strong-password', $user->password));
        $this->assertSame(['Administrator'], $user->getRoleNames()->all());

        $permissions = PermissionCatalogue::names();
        $this->assertSame(count($permissions), Permission::query()->count());
        $this->assertEqualsCanonicalizing($permissions, Role::query()->firstOrFail()->permissions->pluck('name')->all());
    }

    public function test_puts_back_the_reference_data_the_system_cannot_run_without(): void
    {
        Tenant::factory()->create();

        $this->artisan('production:reset', ['--email' => 'owner@example.com', '--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('chart_of_accounts', ['code' => '1200', 'name' => 'Accounts Receivable']);
        $this->assertSame('0.00', Account::query()->where('code', '1200')->firstOrFail()->balance);
        $this->assertGreaterThan(0, Unit::query()->count());
        $this->assertDatabaseHas('expense_categories', ['name' => 'Marketing']);
    }

    public function test_names_the_role_and_shows_a_generated_password_once_when_none_is_given(): void
    {
        Tenant::factory()->create();

        $this->artisan('production:reset', ['--email' => 'owner@example.com', '--role' => 'Owner', '--force' => true])
            ->expectsOutputToContain('shown only now')
            ->assertSuccessful();

        $this->assertSame(['Owner'], User::query()->firstOrFail()->getRoleNames()->all());
        $this->assertDatabaseCount('roles', 1);
    }

    public function test_creates_the_company_when_the_database_has_none(): void
    {
        $this->artisan('production:reset', ['--email' => 'owner@example.com', '--company' => 'Uni Green Line', '--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['name' => 'Uni Green Line', 'slug' => 'uni-green-line']);
        $this->assertSame(Tenant::query()->firstOrFail()->id, User::query()->firstOrFail()->tenant_id);
    }

    public function test_keeps_only_the_company_named_with_the_tenant_option(): void
    {
        $other = Tenant::factory()->create();
        $kept = Tenant::factory()->create();

        $this->artisan('production:reset', ['--email' => 'owner@example.com', '--tenant' => $kept->slug, '--force' => true])->assertSuccessful();

        $this->assertModelExists($kept);
        $this->assertModelMissing($other);
        $this->assertSame($kept->id, User::query()->firstOrFail()->tenant_id);
    }

    public function test_deletes_nothing_when_the_database_name_is_not_typed_back(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();

        $this->artisan('production:reset', ['--email' => 'owner@example.com'])
            ->expectsQuestion('Type the database name ('.DB::getDatabaseName().') to continue', 'something else')
            ->expectsOutputToContain('Nothing was deleted')
            ->assertFailed();

        $this->assertModelExists($customer);
    }

    public function test_deletes_nothing_when_the_email_is_invalid(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();

        $this->artisan('production:reset', ['--email' => 'not-an-email', '--force' => true])
            ->expectsOutputToContain('email')
            ->assertFailed();

        $this->assertModelExists($customer);
    }

    public function test_deletes_nothing_when_the_tenant_slug_is_unknown(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();

        $this->artisan('production:reset', ['--email' => 'owner@example.com', '--tenant' => 'nowhere', '--force' => true])
            ->expectsOutputToContain("No company with the slug 'nowhere'")
            ->assertFailed();

        $this->assertModelExists($customer);
    }

    private function userWithRole(Tenant $tenant, string $role): User
    {
        Role::findOrCreate($role);

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole($role);

        return $user;
    }
}

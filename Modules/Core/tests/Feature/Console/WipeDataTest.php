<?php

namespace Modules\Core\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Purchasing\Models\Supplier;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WipeDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_all_data_except_super_admin_and_administrator_users_with_their_access_setup(): void
    {
        $tenant = Tenant::factory()->create();
        $administrator = $this->userWithRole($tenant, 'Administrator');
        $superAdmin = $this->userWithRole($tenant, 'Super Admin');
        $salesRep = $this->userWithRole($tenant, 'Sales Rep');
        $administrator->createToken('admin-token');
        $salesRep->createToken('rep-token');
        $customer = Customer::factory()->recycle($tenant)->create();
        $supplier = Supplier::factory()->recycle($tenant)->create();

        $this->artisan('data:wipe', ['--force' => true])
            ->expectsOutputToContain('kept 2 user(s)')
            ->assertSuccessful();

        $this->assertModelExists($administrator);
        $this->assertModelExists($superAdmin);
        $this->assertModelMissing($salesRep);
        $this->assertModelMissing($customer);
        $this->assertModelMissing($supplier);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('model_has_roles', 2);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $administrator->id]);
        $this->assertModelExists($tenant);
        $this->assertDatabaseHas('roles', ['name' => 'Sales Rep']);
        $this->assertTrue($administrator->fresh()->hasRole('Administrator'));
    }

    public function test_keeps_the_users_of_the_roles_given_with_the_role_option_instead_of_the_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $administrator = $this->userWithRole($tenant, 'Administrator');
        $owner = $this->userWithRole($tenant, 'Owner');

        $this->artisan('data:wipe', ['--force' => true, '--role' => ['Owner']])->assertSuccessful();

        $this->assertModelExists($owner);
        $this->assertModelMissing($administrator);
    }

    public function test_leaves_the_tables_named_with_the_keep_option_untouched(): void
    {
        $tenant = Tenant::factory()->create();
        $this->userWithRole($tenant, 'Administrator');
        $customer = Customer::factory()->recycle($tenant)->create();
        $supplier = Supplier::factory()->recycle($tenant)->create();

        $this->artisan('data:wipe', ['--force' => true, '--keep' => ['customers']])->assertSuccessful();

        $this->assertModelExists($customer);
        $this->assertModelMissing($supplier);
    }

    public function test_rejects_an_unknown_table_in_the_keep_option_and_deletes_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $this->userWithRole($tenant, 'Administrator');
        $customer = Customer::factory()->recycle($tenant)->create();

        $this->artisan('data:wipe', ['--force' => true, '--keep' => ['custmers']])
            ->expectsOutputToContain('Unknown table(s) in --keep: custmers')
            ->assertFailed();

        $this->assertModelExists($customer);
    }

    public function test_refuses_to_wipe_when_no_user_holds_a_kept_role(): void
    {
        $tenant = Tenant::factory()->create();
        $salesRep = $this->userWithRole($tenant, 'Sales Rep');
        $customer = Customer::factory()->recycle($tenant)->create();

        $this->artisan('data:wipe', ['--force' => true])
            ->expectsOutputToContain('refusing to wipe everything')
            ->assertFailed();

        $this->assertModelExists($salesRep);
        $this->assertModelExists($customer);
    }

    public function test_deletes_nothing_when_the_confirmation_is_declined(): void
    {
        $tenant = Tenant::factory()->create();
        $this->userWithRole($tenant, 'Administrator');
        $customer = Customer::factory()->recycle($tenant)->create();

        $this->artisan('data:wipe')
            ->expectsConfirmation("Permanently delete 1 row(s) from the '".config('database.connections.mysql.database')."' database (testing)? This cannot be undone.", 'no')
            ->expectsOutputToContain('Nothing was deleted')
            ->assertFailed();

        $this->assertModelExists($customer);
    }

    public function test_clears_the_manager_of_a_kept_user_whose_manager_was_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $manager = $this->userWithRole($tenant, 'Sales Manager');
        $administrator = $this->userWithRole($tenant, 'Administrator');
        $administrator->update(['manager_id' => $manager->id]);

        $this->artisan('data:wipe', ['--force' => true])->assertSuccessful();

        $this->assertModelMissing($manager);
        $this->assertNull($administrator->fresh()->manager_id);
    }

    private function userWithRole(Tenant $tenant, string $role): User
    {
        Role::findOrCreate($role);

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole($role);

        return $user;
    }
}

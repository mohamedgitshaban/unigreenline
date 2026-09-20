<?php

namespace Modules\Core\Tests\Feature\Database\Seeders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_all_ten_roles_from_the_spec(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame([
            'Accountant', 'Administrator', 'Auditor', 'Customer Service', 'Owner',
            'Purchasing', 'Sales Manager', 'Sales Rep', 'Warehouse Employee', 'Warehouse Manager',
        ], Role::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_administrator_gets_every_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $administrator = Role::findByName('Administrator');

        $this->assertSame(56, $administrator->permissions()->count());
    }

    /**
     * Spec §2 groups "Own orders/customers only" as one scope for Sales
     * Rep — crm.* is included, not just sales.*, so a rep can manage their
     * own customers.
     */
    public function test_sales_rep_is_limited_to_sales_and_crm_view_add_edit_print(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $salesRep = Role::findByName('Sales Rep');

        $this->assertSame([
            'crm.add', 'crm.edit', 'crm.print', 'crm.view',
            'sales.add', 'sales.edit', 'sales.print', 'sales.view',
        ], $salesRep->permissions()->pluck('name')->sort()->values()->all());
    }

    public function test_auditor_is_read_only_across_every_module(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $auditor = Role::findByName('Auditor');
        $permissions = $auditor->permissions()->pluck('name')->sort()->values()->all();

        $this->assertCount(21, $permissions);
        $this->assertContains('inventory.view', $permissions);
        $this->assertContains('accounting.audit', $permissions);
        $this->assertNotContains('inventory.add', $permissions);
    }
}

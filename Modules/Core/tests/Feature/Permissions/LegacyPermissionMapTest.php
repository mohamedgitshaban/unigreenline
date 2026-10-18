<?php

namespace Modules\Core\Tests\Feature\Permissions;

use Modules\Core\Support\LegacyPermissionMap;
use Tests\TestCase;

class LegacyPermissionMapTest extends TestCase
{
    public function test_a_module_wide_permission_expands_to_every_resource_in_that_module(): void
    {
        $expanded = LegacyPermissionMap::expand(['expenses.view']);

        $this->assertContains('expenses.view', $expanded);
        $this->assertContains('expense-categories.view', $expanded);
        $this->assertNotContains('expenses.create', $expanded);
        $this->assertNotContains('invoices.view', $expanded);
    }

    public function test_the_old_cross_module_or_rules_are_preserved(): void
    {
        $this->assertContains('suppliers.view', LegacyPermissionMap::expand(['accounting.view']));
        $this->assertContains('collections.create', LegacyPermissionMap::expand(['accounting.add']));
        $this->assertContains('units.view', LegacyPermissionMap::expand(['sales.view']));
        $this->assertContains('returns.create', LegacyPermissionMap::expand(['purchasing.add']));
    }

    public function test_actions_needing_two_old_capabilities_need_both(): void
    {
        $this->assertNotContains('sales-orders.quick-deliver', LegacyPermissionMap::expand(['sales.add']));
        $this->assertContains('sales-orders.quick-deliver', LegacyPermissionMap::expand(['sales.add', 'sales.edit']));
    }

    public function test_pricing_is_never_granted_by_the_grid(): void
    {
        $everything = [];
        foreach (LegacyPermissionMap::MODULES as $module) {
            foreach (LegacyPermissionMap::CAPABILITIES as $capability) {
                $everything[] = "{$module}.{$capability}";
            }
        }

        $this->assertNotContains('purchase-orders.pricing', LegacyPermissionMap::expand($everything));
        $this->assertContains('purchase-orders.pricing', LegacyPermissionMap::expand(['purchasing.pricing']));
    }

    public function test_it_tells_old_names_from_new_ones(): void
    {
        $this->assertTrue(LegacyPermissionMap::isLegacy('sales.view'));
        $this->assertTrue(LegacyPermissionMap::isLegacy('purchasing.pricing'));
        $this->assertFalse(LegacyPermissionMap::isLegacy('sales-orders.view'));
        $this->assertFalse(LegacyPermissionMap::isLegacy('invoices.void'));
    }
}

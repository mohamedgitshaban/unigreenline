<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Ten roles drawn from eight capability types across eight modules (spec §2).
 * Administrator additionally gets an unconditional Gate::before bypass
 * (see CoreServiceProvider::boot()) — its permission rows below exist so
 * the UI can still list what an Administrator can do.
 */
class RolePermissionSeeder extends Seeder
{
    private const MODULES = ['sales', 'inventory', 'purchasing', 'crm', 'accounting', 'expenses', 'analytics', 'admin'];

    private const CAPABILITIES = ['view', 'add', 'edit', 'delete', 'approve', 'print', 'export', 'audit'];

    /**
     * `extra` grants single permissions outside the role's module ×
     * capability grid; `except` removes ones the grid would otherwise grant.
     *
     * @var array<string, array{modules: string[]|'*', capabilities: string[], extra?: string[], except?: string[]}>
     */
    private const ROLES = [
        'Administrator' => ['modules' => '*', 'capabilities' => self::CAPABILITIES],
        // Expense approval sits with Accountant/Administrator only, so Owner's
        // module-wide approve stops short of expenses.
        'Owner' => ['modules' => '*', 'capabilities' => ['view', 'approve', 'print', 'export', 'audit'], 'except' => ['expenses.approve']],
        'Sales Manager' => ['modules' => ['sales'], 'capabilities' => ['view', 'add', 'edit', 'approve', 'print', 'export']],
        // Spec §2 groups "Own orders/customers only" as one scope for Sales
        // Rep — crm.* is included here (not just sales.*) so a rep can
        // manage their own customers, not only their own orders.
        'Sales Rep' => ['modules' => ['sales', 'crm'], 'capabilities' => ['view', 'add', 'edit', 'print']],
        'Warehouse Manager' => ['modules' => ['inventory'], 'capabilities' => ['view', 'add', 'edit', 'approve', 'print', 'export']],
        'Warehouse Employee' => ['modules' => ['inventory'], 'capabilities' => ['view', 'add', 'edit']],
        // Approving an expense posts it to the journal, so the accountant
        // approves/rejects expenses — but still not accounting.approve.
        'Accountant' => ['modules' => ['accounting', 'expenses'], 'capabilities' => ['view', 'add', 'edit', 'print', 'export'], 'extra' => ['expenses.approve']],
        'Customer Service' => ['modules' => ['crm'], 'capabilities' => ['view', 'add', 'edit']],
        // Purchasing records expenses but can't approve them.
        'Purchasing' => ['modules' => ['purchasing'], 'capabilities' => ['view', 'add', 'edit', 'approve', 'print'], 'extra' => ['expenses.view', 'expenses.add', 'expenses.edit']],
        'Auditor' => ['modules' => '*', 'capabilities' => ['view', 'export', 'audit']],
    ];

    public function run(): void
    {
        foreach (self::MODULES as $module) {
            foreach (self::CAPABILITIES as $capability) {
                Permission::findOrCreate("{$module}.{$capability}", 'web');
            }
        }

        foreach (self::ROLES as $roleName => $definition) {
            $role = Role::findOrCreate($roleName, 'web');

            $modules = $definition['modules'] === '*' ? self::MODULES : $definition['modules'];

            $permissionNames = [];
            foreach ($modules as $module) {
                foreach ($definition['capabilities'] as $capability) {
                    $permissionNames[] = "{$module}.{$capability}";
                }
            }

            $permissionNames = array_diff([...$permissionNames, ...($definition['extra'] ?? [])], $definition['except'] ?? []);

            $role->syncPermissions(array_values($permissionNames));
        }
    }
}

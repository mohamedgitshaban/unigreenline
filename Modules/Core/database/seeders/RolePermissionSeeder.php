<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Support\LegacyPermissionMap;
use Modules\Core\Support\PermissionCatalogue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The seeded roles. Every API endpoint has its own permission
 * (`{resource}.{action}`, see PermissionCatalogue); the roles below are still
 * described on the coarse module x capability grid for readability and
 * expanded into those per-endpoint permissions by LegacyPermissionMap, so
 * "Sales Manager = the sales module, view/add/edit/..." stays one line.
 * Administrator simply holds every permission, and also has an unconditional
 * Gate::before bypass (see CoreServiceProvider::boot()).
 *
 * Roles are only a starting point: an admin can create new ones and change
 * any role's permissions endpoint by endpoint through the roles API.
 */
class RolePermissionSeeder extends Seeder
{
    private const CAPABILITIES = LegacyPermissionMap::CAPABILITIES;

    /**
     * `extra` adds old-style permissions outside the role's module x capability
     * grid, `except` removes some the grid would otherwise grant, and `grant`
     * adds per-endpoint permissions directly.
     *
     * @var array<string, array{modules: string[]|'*', capabilities: string[], extra?: string[], except?: string[], grant?: string[]}>
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
        'Warehouse Manager' => ['modules' => ['inventory', 'manufacturing', 'recap'], 'capabilities' => ['view', 'add', 'edit', 'approve', 'print', 'export']],
        'Warehouse Employee' => ['modules' => ['inventory', 'manufacturing'], 'capabilities' => ['view', 'add', 'edit']],
        // Approving an expense posts it to the journal, so the accountant
        // approves/rejects expenses — but still not accounting.approve.
        'Accountant' => ['modules' => ['accounting', 'expenses'], 'capabilities' => ['view', 'add', 'edit', 'print', 'export'], 'extra' => ['expenses.approve', 'recap.view'], 'grant' => ['payroll-runs.view', 'payslips.view']],
        'Customer Service' => ['modules' => ['crm'], 'capabilities' => ['view', 'add', 'edit']],
        // Purchasing records expenses but can't approve them.
        'Purchasing' => ['modules' => ['purchasing'], 'capabilities' => ['view', 'add', 'edit', 'approve', 'print'], 'extra' => ['expenses.view', 'expenses.add', 'expenses.edit']],
        // Runs the people side: employees, attendance, leave, time permissions and payroll — including
        // approving and paying a payroll run. (The salaries themselves are only visible to roles that
        // can view payroll.)
        'HR Manager' => ['modules' => ['hr'], 'capabilities' => ['view', 'add', 'edit', 'delete', 'approve', 'print', 'export']],
        // Self-service for staff: check in and out, see their own attendance and payslips, and ask for
        // leave or a time permission. Without employees.view, every one of these shows only their own records.
        'Employee' => ['modules' => [], 'capabilities' => [], 'grant' => [
            'attendances.check-in', 'attendances.check-out', 'attendances.view',
            'time-permissions.view', 'time-permissions.create', 'time-permissions.update', 'time-permissions.delete',
            'leave-requests.view', 'leave-requests.create', 'leave-requests.update', 'leave-requests.delete',
            'payslips.view', 'holidays.view',
        ]],
        'Auditor' => ['modules' => '*', 'capabilities' => ['view', 'export', 'audit']],
    ];

    public function run(): void
    {
        foreach (PermissionCatalogue::names() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::expandedRoles() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }
    }

    /**
     * Every seeded role with the per-endpoint permissions it ends up holding.
     * Administrator holds every permission there is, including any added later.
     *
     * @return array<string, array<int, string>>
     */
    public static function expandedRoles(): array
    {
        $catalogue = PermissionCatalogue::names();
        $roles = [];

        foreach (self::ROLES as $roleName => $definition) {
            if ($roleName === 'Administrator') {
                $roles[$roleName] = $catalogue;

                continue;
            }

            $modules = $definition['modules'] === '*' ? LegacyPermissionMap::MODULES : $definition['modules'];

            $legacy = [];
            foreach ($modules as $module) {
                foreach ($definition['capabilities'] as $capability) {
                    $legacy[] = "{$module}.{$capability}";
                }
            }

            $legacy = array_values(array_diff([...$legacy, ...($definition['extra'] ?? [])], $definition['except'] ?? []));

            // `except` names old-style permissions the grid would otherwise grant; they are removed
            // after expansion so an `expenses.approve` exception also drops `expenses.reject`.
            $except = ($definition['except'] ?? []) === [] ? [] : LegacyPermissionMap::expand($definition['except']);

            $roles[$roleName] = array_values(array_unique([
                ...array_diff(LegacyPermissionMap::expand($legacy), $except),
                ...($definition['grant'] ?? []),
            ]));
            sort($roles[$roleName]);
        }

        return $roles;
    }
}

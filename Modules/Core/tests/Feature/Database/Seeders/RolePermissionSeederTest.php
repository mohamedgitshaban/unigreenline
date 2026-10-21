<?php

namespace Modules\Core\Tests\Feature\Database\Seeders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Support\PermissionCatalogue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame([
            'Accountant', 'Administrator', 'Auditor', 'Customer Service', 'Employee', 'HR Manager', 'Owner',
            'Purchasing', 'Sales Manager', 'Sales Rep', 'Warehouse Employee', 'Warehouse Manager',
        ], Role::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_administrator_gets_every_permission_there_is(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(
            PermissionCatalogue::names(),
            Role::findByName('Administrator', 'web')->permissions()->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_sales_rep_manages_orders_and_customers_but_cannot_delete_or_export(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $permissions = $this->permissionsOf('Sales Rep');

        foreach (['sales-orders.view', 'sales-orders.create', 'sales-orders.update', 'sales-orders.change-status', 'invoices.view', 'customers.view', 'customers.create', 'customers.update', 'units.view', 'products.availability'] as $granted) {
            $this->assertContains($granted, $permissions);
        }

        foreach (['sales-orders.delete', 'sales-orders.export', 'customers.delete', 'invoices.void', 'suppliers.view', 'products.view'] as $withheld) {
            $this->assertNotContains($withheld, $permissions);
        }
    }

    public function test_auditor_is_read_only_across_every_module(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // Chat is talking to colleagues, not changing business records, so every role has it, auditors included.
        $chat = PermissionCatalogue::all()->where('module', 'chat')->keys()->all();

        $actions = collect($this->permissionsOf('Auditor'))->diff($chat)->map(fn (string $name) => explode('.', $name, 2)[1])->unique()->sort()->values()->all();

        $this->assertSame(['export', 'verify', 'view'], array_values(array_diff($actions, ['availability', 'lifecycle', 'costs', 'history', 'schedule', 'traceability', 'due', 'forecast', 'incoming', 'movements', 'outgoing', 'overview', 'preview', 'summary', 'cost-summary', 'resume'])));
        $this->assertContains('products.view', $this->permissionsOf('Auditor'));
        $this->assertContains('audit-log.view', $this->permissionsOf('Auditor'));
        $this->assertNotContains('products.create', $this->permissionsOf('Auditor'));
    }

    public function test_the_accountant_can_reach_what_the_old_or_rules_let_accounting_reach(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $permissions = $this->permissionsOf('Accountant');

        foreach (['chart-of-accounts.view', 'journal-entries.view', 'balance-sheet.view', 'expenses.approve', 'expenses.reject', 'recaps.view', 'suppliers.view', 'invoices.view', 'collections.create', 'ar-aging.view', 'ap-aging.view'] as $granted) {
            $this->assertContains($granted, $permissions);
        }

        $this->assertNotContains('sales-orders.view', $permissions);
        $this->assertNotContains('recaps.approve', $permissions);
    }

    public function test_only_accountant_gets_expense_approval_among_non_administrator_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (['expenses.approve', 'expenses.reject'] as $permission) {
            $holders = Role::query()
                ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
                ->orderBy('name')
                ->pluck('name')
                ->all();

            $this->assertSame(['Accountant', 'Administrator'], $holders);
        }
    }

    public function test_purchasing_can_record_but_not_approve_expenses(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $expensePermissions = collect($this->permissionsOf('Purchasing'))
            ->filter(fn (string $name) => str_starts_with($name, 'expenses.') || str_starts_with($name, 'expense-categories.'))
            ->values()
            ->all();

        $this->assertSame(['expense-categories.create', 'expense-categories.update', 'expense-categories.view', 'expenses.create', 'expenses.update', 'expenses.upload-receipt', 'expenses.view'], $expensePermissions);
    }

    public function test_only_administrator_can_price_purchase_orders(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $holders = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'purchase-orders.pricing'))->pluck('name')->all();

        $this->assertSame(['Administrator'], $holders);
    }

    public function test_hr_manager_runs_people_and_payroll_and_the_employee_role_is_self_service_only(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $hr = $this->permissionsOf('HR Manager');
        foreach (['employees.create', 'attendances.update', 'leave-requests.approve', 'time-permissions.reject', 'payroll-runs.create', 'payroll-runs.approve', 'payroll-runs.pay', 'payslips.view'] as $granted) {
            $this->assertContains($granted, $hr);
        }
        $this->assertNotContains('invoices.view', $hr);
        $this->assertNotContains('purchase-orders.pricing', $hr);

        $employee = $this->permissionsOf('Employee');
        foreach (['attendances.check-in', 'attendances.check-out', 'leave-requests.create', 'payslips.view'] as $granted) {
            $this->assertContains($granted, $employee);
        }
        // Without employees.view the same endpoints show only the employee's own records.
        foreach (['employees.view', 'leave-requests.approve', 'payroll-runs.view', 'attendances.create'] as $withheld) {
            $this->assertNotContains($withheld, $employee);
        }
    }

    /**
     * @return array<int, string>
     */
    private function permissionsOf(string $role): array
    {
        return Role::findByName($role, 'web')->permissions()->pluck('name')->sort()->values()->all();
    }
}

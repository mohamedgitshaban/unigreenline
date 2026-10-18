<?php

namespace Modules\HR\Tests\Feature;

use Modules\Core\Models\User;
use Modules\HR\Models\Attendance;
use Modules\HR\Models\Department;
use Modules\HR\Models\Holiday;
use Modules\HR\Models\WorkSchedule;
use Spatie\Permission\Models\Role;

class HrSetupTest extends HrTestCase
{
    public function test_departments_are_managed_and_unique_per_company(): void
    {
        $this->actingAsRole('HR Manager');

        $id = $this->postJson('/api/v1/departments', ['name' => 'Warehouse'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/departments', ['name' => 'Warehouse'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("/api/v1/departments/{$id}", ['name' => 'Logistics', 'active' => false])->assertOk()->assertJsonPath('data.name', 'Logistics');
        $this->employee(['department_id' => $id]);
        $this->getJson('/api/v1/departments')->assertOk()->assertJsonPath('data.0.employees_count', 1);
        // Employees keep their record (the department link is simply cleared).
        $this->deleteJson("/api/v1/departments/{$id}")->assertNoContent();
    }

    public function test_work_schedules_have_exactly_one_default(): void
    {
        $this->actingAsRole('HR Manager');
        $payload = ['start_time' => '08:00', 'end_time' => '16:30', 'break_minutes' => 30, 'grace_minutes' => 10, 'weekend_days' => [5, 6]];

        $first = $this->postJson('/api/v1/work-schedules', $payload + ['name' => 'Day shift', 'is_default' => true])
            ->assertCreated()
            ->assertJsonPath('data.scheduled_minutes', 480)
            ->json('data.id');
        $second = $this->postJson('/api/v1/work-schedules', $payload + ['name' => 'Short shift', 'is_default' => true])->assertCreated()->json('data.id');

        $this->assertFalse(WorkSchedule::findOrFail($first)->is_default);
        $this->assertTrue(WorkSchedule::findOrFail($second)->is_default);

        $this->postJson('/api/v1/work-schedules', ['name' => 'Bad'] + array_merge($payload, ['end_time' => '07:00']))->assertUnprocessable()->assertJsonValidationErrors('end_time');
        $this->postJson('/api/v1/work-schedules', ['name' => 'Bad'] + array_merge($payload, ['weekend_days' => [9]]))->assertUnprocessable();
    }

    public function test_global_holidays_can_span_several_days(): void
    {
        $this->actingAsRole('HR Manager');

        $id = $this->postJson('/api/v1/holidays', ['name' => 'Eid', 'start_date' => '2026-10-01', 'end_date' => '2026-10-03'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/holidays', ['name' => 'Bad', 'start_date' => '2026-10-05', 'end_date' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->putJson("/api/v1/holidays/{$id}", ['end_date' => '2026-10-04'])->assertOk()->assertJsonPath('data.end_date', '2026-10-04');
        $this->assertSame(1, Holiday::query()->count());
    }

    public function test_employees_have_salary_details_only_payroll_staff_can_see(): void
    {
        $this->actingAsRole('HR Manager');
        $login = User::factory()->recycle($this->tenant)->create();

        $id = $this->postJson('/api/v1/employees', [
            'code' => 'E-100', 'name' => 'Mona Ali', 'job_title' => 'Packer', 'hire_date' => '2024-01-01', 'basic_salary' => 4000,
            'allowances' => 500, 'insurance_pct' => 11, 'tax_pct' => 10, 'overtime_eligible' => true, 'user_id' => $login->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.basic_salary', '4000.00')
            ->assertJsonPath('data.user_id', $login->id)
            ->json('data.id');

        $this->postJson('/api/v1/employees', ['code' => 'E-100', 'name' => 'Dup', 'hire_date' => '2024-01-01', 'basic_salary' => 1])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/employees', ['code' => 'E-101', 'name' => 'Same login', 'hire_date' => '2024-01-01', 'basic_salary' => 1, 'user_id' => $login->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        // Someone who can view employees but not payroll sees the person, not the pay.
        $viewer = Role::create(['name' => 'Directory', 'guard_name' => 'web']);
        $viewer->givePermissionTo('employees.view');
        $this->actingAsRole('Directory');
        $this->getJson("/api/v1/employees/{$id}")->assertOk()->assertJsonPath('data.name', 'Mona Ali')->assertJsonMissingPath('data.basic_salary');
    }

    public function test_an_employee_with_history_cannot_be_deleted_but_a_clean_one_can(): void
    {
        $this->actingAsRole('Administrator');
        $clean = $this->employee();
        $withHistory = $this->employee();
        $this->attend($withHistory, ['2026-09-02']);

        $this->deleteJson("/api/v1/employees/{$clean->id}")->assertNoContent();
        $this->deleteJson("/api/v1/employees/{$withHistory->id}")->assertConflict();
        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_hr_data_is_tenant_scoped(): void
    {
        $this->actingAsRole('Administrator');
        $foreign = Department::factory()->create();

        $this->getJson("/api/v1/departments/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/v1/departments/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/departments/{$foreign->id}")->assertNotFound();
    }

    public function test_permissions_are_per_endpoint(): void
    {
        $this->actingAsRole('Sales Manager');

        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->getJson('/api/v1/departments')->assertForbidden();

        $this->actingAsRole('Employee');
        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->getJson('/api/v1/holidays')->assertOk();
        $this->postJson('/api/v1/holidays', ['name' => 'x', 'start_date' => '2026-01-01', 'end_date' => '2026-01-01'])->assertForbidden();
    }
}

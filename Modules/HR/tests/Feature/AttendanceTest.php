<?php

namespace Modules\HR\Tests\Feature;

use Illuminate\Support\Carbon;
use Modules\HR\Models\Attendance;
use Modules\HR\Models\WorkSchedule;

class AttendanceTest extends HrTestCase
{
    public function test_hr_keys_a_day_in_and_the_late_early_and_overtime_minutes_are_worked_out(): void
    {
        $this->actingAsRole('HR Manager');
        $employee = $this->employee();

        $this->postJson('/api/v1/attendances', ['employee_id' => $employee->id, 'work_date' => '2026-09-02', 'check_in' => '09:20', 'check_out' => '16:30'])
            ->assertCreated()
            ->assertJsonPath('data.late_minutes', 20)
            ->assertJsonPath('data.early_leave_minutes', 30)
            ->assertJsonPath('data.overtime_minutes', 0)
            ->assertJsonPath('data.worked_minutes', 430)
            ->assertJsonPath('data.scheduled_minutes', 480);

        $this->postJson('/api/v1/attendances', ['employee_id' => $employee->id, 'work_date' => '2026-09-03', 'check_in' => '09:00', 'check_out' => '18:30'])
            ->assertCreated()
            ->assertJsonPath('data.overtime_minutes', 90);
    }

    public function test_the_employees_own_schedule_is_what_counts_including_the_break(): void
    {
        $this->actingAsRole('HR Manager');
        $schedule = WorkSchedule::factory()->recycle($this->tenant)->create(['start_time' => '08:00:00', 'end_time' => '16:30:00', 'break_minutes' => 30]);
        $employee = $this->employee(['work_schedule_id' => $schedule->id]);

        $this->postJson('/api/v1/attendances', ['employee_id' => $employee->id, 'work_date' => '2026-09-02', 'check_in' => '08:00', 'check_out' => '16:30'])
            ->assertCreated()
            ->assertJsonPath('data.scheduled_minutes', 480)
            ->assertJsonPath('data.worked_minutes', 480)
            ->assertJsonPath('data.late_minutes', 0);
    }

    public function test_one_record_per_employee_per_day_and_check_out_must_follow_check_in(): void
    {
        $this->actingAsRole('HR Manager');
        $employee = $this->employee();
        $payload = ['employee_id' => $employee->id, 'work_date' => '2026-09-02', 'check_in' => '09:00', 'check_out' => '17:00'];

        $this->postJson('/api/v1/attendances', $payload)->assertCreated();
        $this->postJson('/api/v1/attendances', $payload)->assertUnprocessable()->assertJsonValidationErrors('work_date');
        $this->postJson('/api/v1/attendances', ['check_out' => '08:00', 'work_date' => '2026-09-03'] + $payload)->assertUnprocessable()->assertJsonValidationErrors('check_out');
        $this->postJson('/api/v1/attendances', ['work_date' => now()->addDay()->toDateString()] + $payload)->assertUnprocessable()->assertJsonValidationErrors('work_date');
    }

    public function test_hr_can_correct_and_delete_a_record(): void
    {
        $this->actingAsRole('Administrator');
        $employee = $this->employee();
        $id = $this->postJson('/api/v1/attendances', ['employee_id' => $employee->id, 'work_date' => '2026-09-02', 'check_in' => '10:00', 'check_out' => '17:00'])->json('data.id');

        $this->putJson("/api/v1/attendances/{$id}", ['check_in' => '09:00'])->assertOk()->assertJsonPath('data.late_minutes', 0)->assertJsonPath('data.worked_minutes', 480);
        $this->putJson("/api/v1/attendances/{$id}", ['check_out' => '08:00'])->assertUnprocessable()->assertJsonValidationErrors('check_out');
        $this->deleteJson("/api/v1/attendances/{$id}")->assertNoContent();
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_an_employee_checks_in_and_out_for_themselves(): void
    {
        $me = $this->actingAsRole('Employee');
        $employee = $this->employee(['user_id' => $me->id]);

        Carbon::setTestNow('2026-09-02 09:12:00');
        $this->postJson('/api/v1/attendances/check-in')->assertCreated()->assertJsonPath('data.check_in', '09:12')->assertJsonPath('data.late_minutes', 12)->assertJsonPath('data.incomplete', true)->assertJsonPath('data.source', 'self');
        $this->postJson('/api/v1/attendances/check-in')->assertUnprocessable()->assertJsonPath('message', 'You have already checked in today.');

        Carbon::setTestNow('2026-09-02 17:45:00');
        $this->postJson('/api/v1/attendances/check-out')->assertOk()->assertJsonPath('data.overtime_minutes', 45)->assertJsonPath('data.incomplete', false);
        $this->postJson('/api/v1/attendances/check-out')->assertUnprocessable()->assertJsonPath('message', 'You have already checked out today.');

        $this->assertSame($employee->id, Attendance::query()->firstOrFail()->employee_id);
    }

    public function test_checking_out_needs_a_check_in_and_a_login_linked_to_an_employee(): void
    {
        $me = $this->actingAsRole('Employee');

        $this->postJson('/api/v1/attendances/check-in')->assertUnprocessable()->assertJsonPath('message', 'Your login is not linked to an employee record. Ask HR to link it.');

        $this->employee(['user_id' => $me->id]);
        $this->postJson('/api/v1/attendances/check-out')->assertUnprocessable()->assertJsonPath('message', 'You have not checked in today.');
    }

    public function test_an_employee_sees_only_their_own_attendance_but_hr_sees_everyones(): void
    {
        $me = $this->actingAsRole('Employee');
        $mine = $this->employee(['user_id' => $me->id]);
        $theirs = $this->employee();
        $this->attend($mine, ['2026-09-02']);
        $this->attend($theirs, ['2026-09-02', '2026-09-03']);
        $theirRecord = Attendance::query()->where('employee_id', $theirs->id)->firstOrFail();

        $this->getJson('/api/v1/attendances')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $mine->id);
        $this->getJson("/api/v1/attendances/{$theirRecord->id}")->assertNotFound();
        $this->getJson('/api/v1/attendances/summary?month=2026-09')->assertOk()->assertJsonCount(1, 'data.employees');

        $this->actingAsRole('HR Manager');
        $this->getJson('/api/v1/attendances')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/attendances/summary?month=2026-09')->assertOk()->assertJsonCount(2, 'data.employees');
    }

    public function test_the_monthly_summary_totals_each_employee(): void
    {
        $this->actingAsRole('HR Manager');
        $employee = $this->employee();
        $this->attend($employee, ['2026-09-02', '2026-09-03'], '09:30', '17:00');
        $this->attend($employee, ['2026-09-06'], '09:00', '18:00');

        $response = $this->getJson("/api/v1/attendances/summary?month=2026-09&employee_id={$employee->id}")->assertOk();

        $response->assertJsonPath('data.employees.0.days_present', 3)
            ->assertJsonPath('data.employees.0.late_minutes', 60)
            ->assertJsonPath('data.employees.0.overtime_minutes', 60)
            ->assertJsonPath('data.employees.0.worked_minutes', 450 + 450 + 540);
    }

    public function test_exporting_needs_its_own_permission(): void
    {
        $this->actingAsRole('Employee');
        $this->get('/api/v1/attendances?export=csv')->assertForbidden();

        $this->actingAsRole('HR Manager');
        $this->get('/api/v1/attendances?export=csv')->assertOk()->assertDownload();
    }
}

<?php

namespace Modules\HR\Tests\Feature;

use Modules\HR\Models\Holiday;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\TimePermission;

class LeaveAndPermissionTest extends HrTestCase
{
    public function test_a_time_permission_has_its_minutes_worked_out_and_goes_through_approval(): void
    {
        $this->actingAsRole('HR Manager');
        $employee = $this->employee();

        $id = $this->postJson('/api/v1/time-permissions', [
            'employee_id' => $employee->id, 'type' => 'late_arrival', 'permission_date' => '2026-09-02',
            'from_time' => '09:00', 'to_time' => '10:30', 'reason' => 'Doctor',
        ])->assertCreated()->assertJsonPath('data.minutes', 90)->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->postJson("/api/v1/time-permissions/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/time-permissions/{$id}/approve")->assertUnprocessable()->assertJsonPath('message', 'Only a pending permission can be changed.');
        $this->putJson("/api/v1/time-permissions/{$id}", ['reason' => 'x'])->assertUnprocessable();
        $this->deleteJson("/api/v1/time-permissions/{$id}")->assertUnprocessable();
    }

    public function test_the_three_kinds_and_a_rejection(): void
    {
        $this->actingAsRole('HR Manager');
        $employee = $this->employee();

        foreach (['early_leave' => ['15:00', '17:00'], 'partial_absence' => ['11:00', '13:00']] as $type => [$from, $to]) {
            $this->postJson('/api/v1/time-permissions', ['employee_id' => $employee->id, 'type' => $type, 'permission_date' => '2026-09-02', 'from_time' => $from, 'to_time' => $to])
                ->assertCreated()->assertJsonPath('data.minutes', 120);
        }

        $id = TimePermission::query()->firstOrFail()->id;
        $this->postJson("/api/v1/time-permissions/{$id}/reject", ['reason' => 'No cover'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'No cover');
        $this->postJson('/api/v1/time-permissions', ['employee_id' => $employee->id, 'type' => 'bogus', 'permission_date' => '2026-09-02', 'from_time' => '10:00', 'to_time' => '09:00'])->assertUnprocessable();
    }

    public function test_an_employee_asks_for_their_own_permission_and_cannot_approve_it_or_see_others(): void
    {
        $me = $this->actingAsRole('Employee');
        $mine = $this->employee(['user_id' => $me->id]);
        $other = $this->employee();

        $id = $this->postJson('/api/v1/time-permissions', [
            'employee_id' => $other->id, // ignored: a self-service request is always for yourself
            'type' => 'early_leave', 'permission_date' => '2026-09-02', 'from_time' => '16:00', 'to_time' => '17:00',
        ])->assertCreated()->assertJsonPath('data.employee.id', $mine->id)->json('data.id');
        $foreign = TimePermission::factory()->recycle($this->tenant)->create(['employee_id' => $other->id]);

        $this->postJson("/api/v1/time-permissions/{$id}/approve")->assertForbidden();
        $this->getJson('/api/v1/time-permissions')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/time-permissions/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/v1/time-permissions/{$id}", ['to_time' => '17:30'])->assertOk()->assertJsonPath('data.minutes', 90);
        $this->deleteJson("/api/v1/time-permissions/{$id}")->assertNoContent();
    }

    public function test_leave_counts_only_working_days(): void
    {
        $this->actingAsRole('HR Manager');
        $employee = $this->employee();
        Holiday::factory()->recycle($this->tenant)->create(['start_date' => '2026-09-10', 'end_date' => '2026-09-10']);

        // Sun 6 → Sat 12 Sep 2026: Fri 11 and Sat 12 are the weekend, Thu 10 is a holiday, so 4 working days.
        $this->postJson('/api/v1/leave-requests', ['employee_id' => $employee->id, 'type' => 'annual', 'start_date' => '2026-09-06', 'end_date' => '2026-09-12'])
            ->assertCreated()
            ->assertJsonPath('data.days', 4)
            ->assertJsonPath('data.paid', true);

        // A range that is all weekend has nothing to take.
        $this->postJson('/api/v1/leave-requests', ['employee_id' => $employee->id, 'type' => 'sick', 'start_date' => '2026-09-18', 'end_date' => '2026-09-19'])
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_annual_leave_is_capped_by_the_entitlement_and_overlaps_are_refused(): void
    {
        $this->actingAsRole('HR Manager');
        $employee = $this->employee(['annual_leave_days' => 5]);

        $first = $this->postJson('/api/v1/leave-requests', ['employee_id' => $employee->id, 'type' => 'annual', 'start_date' => '2026-09-06', 'end_date' => '2026-09-09'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/employees/{$employee->id}/leave-balance?year=2026")->assertOk()->assertJsonPath('data.entitlement', 5)->assertJsonPath('data.pending', 4)->assertJsonPath('data.remaining', 1);

        $this->postJson('/api/v1/leave-requests', ['employee_id' => $employee->id, 'type' => 'annual', 'start_date' => '2026-09-13', 'end_date' => '2026-09-15'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson('/api/v1/leave-requests', ['employee_id' => $employee->id, 'type' => 'sick', 'start_date' => '2026-09-08', 'end_date' => '2026-09-08'])
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');
        // Sick and unpaid leave don't touch the annual balance.
        $this->postJson('/api/v1/leave-requests', ['employee_id' => $employee->id, 'type' => 'sick', 'start_date' => '2026-09-13', 'end_date' => '2026-09-15'])->assertCreated();

        $this->postJson("/api/v1/leave-requests/{$first}/approve")->assertOk();
        $this->getJson("/api/v1/employees/{$employee->id}/leave-balance?year=2026")->assertJsonPath('data.taken', 4)->assertJsonPath('data.pending', 0);
    }

    public function test_leave_can_be_rejected_edited_while_pending_and_a_self_service_user_is_scoped(): void
    {
        $me = $this->actingAsRole('Employee');
        $this->employee(['user_id' => $me->id]);
        $id = $this->postJson('/api/v1/leave-requests', ['type' => 'unpaid', 'start_date' => '2026-09-06', 'end_date' => '2026-09-07'])->assertCreated()->assertJsonPath('data.paid', false)->json('data.id');

        $this->putJson("/api/v1/leave-requests/{$id}", ['end_date' => '2026-09-08'])->assertOk()->assertJsonPath('data.days', 3);

        $this->actingAsRole('HR Manager');
        $this->postJson("/api/v1/leave-requests/{$id}/reject", ['reason' => 'Busy week'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->putJson("/api/v1/leave-requests/{$id}", ['end_date' => '2026-09-09'])->assertUnprocessable();
        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_requests_need_a_linked_employee_when_self_service_and_hr_must_name_one(): void
    {
        $this->actingAsRole('Employee');
        $this->postJson('/api/v1/leave-requests', ['type' => 'annual', 'start_date' => '2026-09-06', 'end_date' => '2026-09-06'])->assertUnprocessable()->assertJsonValidationErrors('employee_id');

        $this->actingAsRole('HR Manager');
        $this->postJson('/api/v1/leave-requests', ['type' => 'annual', 'start_date' => '2026-09-06', 'end_date' => '2026-09-06'])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    }
}

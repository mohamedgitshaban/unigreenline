<?php

namespace Modules\HR\Tests\Feature;

use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Holiday;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\PayrollRun;
use Modules\HR\Models\Payslip;
use Modules\HR\Models\TimePermission;
use Modules\HR\Models\WorkSchedule;
use Modules\HR\Services\GeneratePayrollService;

/**
 * September 2026 has 30 days, 8 of them Fridays and Saturdays, so 22 working days.
 * A 3000 salary is therefore 100 a day and, on an 8-hour schedule, 12.50 an hour.
 */
class PayrollTest extends HrTestCase
{
    private const PERIOD = '2026-09';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-02 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_full_month_pays_the_full_salary(): void
    {
        $employee = $this->employee(['basic_salary' => 2500, 'allowances' => 500]);
        $this->attend($employee, $this->workingDays(self::PERIOD));

        $slip = $this->slipFor($employee);

        $this->assertSame(22, $slip->working_days);
        $this->assertSame(8, $slip->weekend_days);
        $this->assertSame(22, $slip->present_days);
        $this->assertSame('3000.00', $slip->gross_earnings);
        $this->assertSame('0.00', $slip->attendance_deductions);
        $this->assertSame('3000.00', $slip->net_pay);
    }

    public function test_an_absent_day_costs_a_days_salary(): void
    {
        $employee = $this->employee(['basic_salary' => 3000]);
        $this->attend($employee, array_slice($this->workingDays(self::PERIOD), 2));

        $slip = $this->slipFor($employee);

        $this->assertSame(2, $slip->absent_days);
        $this->assertSame('200.00', $slip->absence_deduction);
        $this->assertSame('2800.00', $slip->net_pay);
    }

    public function test_a_company_holiday_is_paid_and_not_an_absence(): void
    {
        $employee = $this->employee(['basic_salary' => 3000]);
        Holiday::factory()->recycle($this->tenant)->create(['start_date' => '2026-09-09', 'end_date' => '2026-09-10']);
        $this->attend($employee, array_values(array_diff($this->workingDays(self::PERIOD), ['2026-09-09', '2026-09-10'])));

        $slip = $this->slipFor($employee);

        $this->assertSame(2, $slip->holiday_days);
        $this->assertSame(20, $slip->working_days);
        $this->assertSame(0, $slip->absent_days);
        $this->assertSame('3000.00', $slip->net_pay);
    }

    public function test_lateness_and_early_leave_are_deducted_by_the_hour_unless_excused_by_an_approved_permission(): void
    {
        $employee = $this->employee(['basic_salary' => 3000]);
        $days = $this->workingDays(self::PERIOD);
        $this->attend($employee, array_slice($days, 2));
        $this->attend($employee, [$days[0]], '10:00', '17:00');   // 60 minutes late, no permission
        $this->attend($employee, [$days[1]], '09:00', '15:00');   // 120 minutes early, with an approved permission
        TimePermission::factory()->recycle($this->tenant)->create(['employee_id' => $employee->id, 'type' => 'early_leave', 'permission_date' => $days[1], 'from_time' => '15:00', 'to_time' => '17:00', 'minutes' => 120, 'status' => 'approved']);

        $slip = $this->slipFor($employee);

        $this->assertSame(60, $slip->late_minutes);
        $this->assertSame(120, $slip->early_leave_minutes);
        $this->assertSame(180, $slip->shortfall_minutes);
        $this->assertSame(120, $slip->excused_minutes);
        $this->assertSame(60, $slip->unexcused_minutes);
        $this->assertSame('12.50', $slip->shortfall_deduction);   // one hour at 12.50
        $this->assertSame('2987.50', $slip->net_pay);
    }

    public function test_a_permission_that_is_pending_or_rejected_excuses_nothing(): void
    {
        $employee = $this->employee(['basic_salary' => 3000]);
        $days = $this->workingDays(self::PERIOD);
        $this->attend($employee, array_slice($days, 1));
        $this->attend($employee, [$days[0]], '10:00', '17:00');
        TimePermission::factory()->recycle($this->tenant)->create(['employee_id' => $employee->id, 'permission_date' => $days[0], 'minutes' => 60, 'status' => 'pending']);

        $this->assertSame('12.50', $this->slipFor($employee)->shortfall_deduction);
    }

    public function test_the_schedules_grace_minutes_are_forgiven_once_a_day(): void
    {
        $schedule = WorkSchedule::factory()->recycle($this->tenant)->create(['grace_minutes' => 15]);
        $employee = $this->employee(['basic_salary' => 3000, 'work_schedule_id' => $schedule->id]);
        $days = $this->workingDays(self::PERIOD);
        $this->attend($employee, array_slice($days, 2));
        $this->attend($employee, [$days[0]], '09:10', '17:00');   // 10 late: inside the grace
        $this->attend($employee, [$days[1]], '09:45', '17:00');   // 45 late: 30 left after the grace

        $slip = $this->slipFor($employee);

        $this->assertSame(30, $slip->unexcused_minutes);
        $this->assertSame('6.25', $slip->shortfall_deduction);
    }

    public function test_overtime_is_paid_only_to_overtime_eligible_employees(): void
    {
        $eligible = $this->employee(['basic_salary' => 3000, 'overtime_eligible' => true]);
        $other = $this->employee(['basic_salary' => 3000, 'overtime_eligible' => false]);
        $days = $this->workingDays(self::PERIOD);
        foreach ([$eligible, $other] as $employee) {
            $this->attend($employee, array_slice($days, 1));
            $this->attend($employee, [$days[0]], '09:00', '19:00');   // 2 hours over
        }

        // 2h × 12.50 × 1.5.
        $this->assertSame('37.50', $this->slipFor($eligible)->overtime_pay);
        $this->assertSame('3037.50', $this->slipFor($eligible)->net_pay);
        $this->assertSame('0.00', $this->slipFor($other)->overtime_pay);
        $this->assertSame(120, $this->slipFor($other)->overtime_minutes);
    }

    public function test_paid_leave_costs_nothing_and_unpaid_leave_costs_a_day(): void
    {
        $employee = $this->employee(['basic_salary' => 3000]);
        $days = $this->workingDays(self::PERIOD);
        $this->attend($employee, array_slice($days, 3));
        LeaveRequest::factory()->recycle($this->tenant)->create(['employee_id' => $employee->id, 'type' => 'annual', 'start_date' => $days[0], 'end_date' => $days[0], 'days' => 1, 'status' => 'approved']);
        LeaveRequest::factory()->recycle($this->tenant)->create(['employee_id' => $employee->id, 'type' => 'unpaid', 'start_date' => $days[1], 'end_date' => $days[2], 'days' => 2, 'status' => 'approved']);

        $slip = $this->slipFor($employee);

        $this->assertSame(1, $slip->paid_leave_days);
        $this->assertSame(2, $slip->unpaid_leave_days);
        $this->assertSame(0, $slip->absent_days);
        $this->assertSame('200.00', $slip->unpaid_leave_deduction);
        $this->assertSame('2800.00', $slip->net_pay);
    }

    public function test_leave_that_is_still_pending_leaves_the_day_an_absence(): void
    {
        $employee = $this->employee(['basic_salary' => 3000]);
        $days = $this->workingDays(self::PERIOD);
        $this->attend($employee, array_slice($days, 1));
        LeaveRequest::factory()->recycle($this->tenant)->create(['employee_id' => $employee->id, 'type' => 'annual', 'start_date' => $days[0], 'end_date' => $days[0], 'status' => 'pending']);

        $this->assertSame(1, $this->slipFor($employee)->absent_days);
    }

    public function test_someone_hired_mid_month_is_paid_for_their_part_of_it(): void
    {
        $employee = $this->employee(['basic_salary' => 3000, 'hire_date' => '2026-09-16']);
        $joined = array_values(array_filter($this->workingDays(self::PERIOD), fn ($d) => $d >= '2026-09-16'));
        $this->attend($employee, $joined);

        $slip = $this->slipFor($employee);

        $this->assertSame(15, $slip->period_days);
        $this->assertSame('1500.00', $slip->gross_earnings);
        $this->assertSame(0, $slip->absent_days);
        $this->assertSame('1500.00', $slip->net_pay);
    }

    public function test_someone_who_left_mid_month_is_paid_until_their_last_day_and_someone_who_left_before_is_left_out(): void
    {
        $leaver = $this->employee(['basic_salary' => 3000, 'termination_date' => '2026-09-15', 'status' => 'inactive']);
        $gone = $this->employee(['basic_salary' => 3000, 'termination_date' => '2026-08-31', 'status' => 'inactive']);
        $this->attend($leaver, array_values(array_filter($this->workingDays(self::PERIOD), fn ($d) => $d <= '2026-09-15')));

        $run = $this->generate();

        $this->assertNull(Payslip::query()->where('employee_id', $gone->id)->first());
        $this->assertSame('1500.00', Payslip::query()->where('employee_id', $leaver->id)->firstOrFail()->net_pay);
        $this->assertSame(1, $run->employees_count);
    }

    public function test_insurance_is_a_share_of_basic_and_tax_a_share_of_what_is_left(): void
    {
        $employee = $this->employee(['basic_salary' => 2000, 'allowances' => 1000, 'insurance_pct' => 11, 'tax_pct' => 10]);
        $this->attend($employee, $this->workingDays(self::PERIOD));

        $slip = $this->slipFor($employee);

        $this->assertSame('3000.00', $slip->earned_pay);
        $this->assertSame('220.00', $slip->insurance);     // 11% of the 2000 basic
        $this->assertSame('278.00', $slip->tax);           // 10% of (3000 − 220)
        $this->assertSame('2502.00', $slip->net_pay);
    }

    public function test_the_run_totals_add_up(): void
    {
        $a = $this->employee(['basic_salary' => 3000, 'insurance_pct' => 10]);
        $b = $this->employee(['basic_salary' => 1500]);
        $this->attend($a, $this->workingDays(self::PERIOD));
        $this->attend($b, array_slice($this->workingDays(self::PERIOD), 1));

        $run = $this->generate();

        // b is absent a day: 1500 − 1500/30 = 1450.
        $this->assertSame(2, $run->employees_count);
        $this->assertSame('4450.00', $run->total_earned);
        $this->assertSame('300.00', $run->total_withheld);
        $this->assertSame('4150.00', $run->total_net);
    }

    public function test_payroll_can_only_be_calculated_once_the_month_has_ended_and_only_once(): void
    {
        $this->actingAsRole('HR Manager');
        $this->employee();

        Carbon::setTestNow('2026-09-20');
        $this->postJson('/api/v1/payroll-runs', ['period' => '2026-09'])->assertUnprocessable()->assertJsonPath('message', 'Payroll can only be calculated once the month has ended — on its last day or later.');

        Carbon::setTestNow('2026-09-30 23:30:00');
        $this->postJson('/api/v1/payroll-runs', ['period' => '2026-09'])->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->postJson('/api/v1/payroll-runs', ['period' => '2026-09'])->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->postJson('/api/v1/payroll-runs', ['period' => 'September'])->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_a_run_with_nobody_on_the_payroll_is_refused(): void
    {
        $this->actingAsRole('HR Manager');

        $this->postJson('/api/v1/payroll-runs', ['period' => self::PERIOD])->assertUnprocessable()->assertJsonPath('message', 'No employees are on the payroll for 2026-09.');
        $this->assertSame(0, PayrollRun::query()->count());
    }

    public function test_a_draft_is_recalculated_after_attendance_is_corrected(): void
    {
        $employee = $this->employee(['basic_salary' => 3000]);
        $this->attend($employee, array_slice($this->workingDays(self::PERIOD), 1));
        $run = $this->generate();
        $this->assertSame('2900.00', $run->total_net);

        $this->attend($employee, [$this->workingDays(self::PERIOD)[0]]);
        $this->actingAsRole('HR Manager');
        $this->putJson("/api/v1/payroll-runs/{$run->id}/recalculate")->assertOk()->assertJsonPath('data.total_net', '3000.00');

        $this->assertSame(1, Payslip::query()->count());
    }

    public function test_approving_posts_the_salaries_expense_and_what_is_owed_and_paying_clears_it(): void
    {
        $employee = $this->employee(['basic_salary' => 3000, 'insurance_pct' => 10]);
        $this->attend($employee, $this->workingDays(self::PERIOD));
        $run = $this->generate();
        $this->actingAsRole('HR Manager');

        $this->postJson("/api/v1/payroll-runs/{$run->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        // Earned 3000: insurance 300 withheld, net 2700 owed to the employee.
        $this->assertSame('3000.00', $this->balance('6100'));
        $this->assertSame('300.00', $this->balance('2210'));
        $this->assertSame('2700.00', $this->balance('2200'));
        $this->assertDatabaseHas('journal_entries', ['ref' => $run->id, 'entry_date' => '2026-09-30']);

        $this->postJson("/api/v1/payroll-runs/{$run->id}/approve")->assertUnprocessable();
        $this->putJson("/api/v1/payroll-runs/{$run->id}/recalculate")->assertUnprocessable();
        $this->deleteJson("/api/v1/payroll-runs/{$run->id}")->assertUnprocessable();

        $this->postJson("/api/v1/payroll-runs/{$run->id}/pay", ['payment_method' => 'bank', 'payment_date' => '2026-10-01'])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame('0.00', $this->balance('2200'));      // salaries payable cleared
        $this->assertSame('-2700.00', $this->balance('1120'));  // bank down by the net pay
        $this->assertSame('300.00', $this->balance('2210'));    // still owed to the authorities
        $this->postJson("/api/v1/payroll-runs/{$run->id}/pay", ['payment_method' => 'cash'])->assertUnprocessable()->assertJsonPath('message', 'This payroll run has already been paid.');
    }

    public function test_a_run_cannot_be_paid_before_it_is_approved_and_a_draft_can_be_deleted(): void
    {
        $this->employee();
        $run = $this->generate();
        $this->actingAsRole('HR Manager');

        $this->postJson("/api/v1/payroll-runs/{$run->id}/pay", ['payment_method' => 'cash'])->assertUnprocessable()->assertJsonPath('message', 'Approve the payroll run before paying it.');
        $this->postJson("/api/v1/payroll-runs/{$run->id}/pay", ['payment_method' => 'cheque'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->deleteJson("/api/v1/payroll-runs/{$run->id}")->assertNoContent();
        $this->assertSame(0, Payslip::query()->count());
    }

    public function test_payslips_can_be_listed_per_run_and_an_employee_sees_only_their_own_after_approval(): void
    {
        $me = $this->actingAsRole('Employee');
        $mine = $this->employee(['user_id' => $me->id, 'basic_salary' => 3000]);
        $other = $this->employee(['basic_salary' => 4000]);
        $this->attend($mine, $this->workingDays(self::PERIOD));
        $this->attend($other, $this->workingDays(self::PERIOD));
        $run = $this->generate();

        $this->actingAsRole('Employee')->forceFill([])->save();
        // Still a draft: an employee sees nothing yet.
        $this->actingAs($me, 'sanctum');
        $this->getJson('/api/v1/payslips')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAsRole('HR Manager');
        $this->getJson("/api/v1/payroll-runs/{$run->id}/payslips")->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/payroll-runs/{$run->id}/approve")->assertOk();

        $this->actingAs($me, 'sanctum');
        $this->getJson('/api/v1/payslips')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_name', $mine->name)->assertJsonPath('data.0.net_pay', '3000.00');
        $otherSlip = Payslip::query()->where('employee_id', $other->id)->firstOrFail();
        $this->getJson("/api/v1/payslips/{$otherSlip->id}")->assertNotFound();
        $this->getJson("/api/v1/payroll-runs/{$run->id}")->assertForbidden();
    }

    public function test_permissions_separate_hr_from_accountant_from_everyone_else(): void
    {
        $this->employee();
        $run = $this->generate();

        $this->actingAsRole('Accountant');
        $this->getJson('/api/v1/payroll-runs')->assertOk();
        $this->postJson('/api/v1/payroll-runs', ['period' => '2026-08'])->assertForbidden();
        $this->postJson("/api/v1/payroll-runs/{$run->id}/approve")->assertForbidden();

        $this->actingAsRole('Sales Manager');
        $this->getJson('/api/v1/payroll-runs')->assertForbidden();

        $this->actingAsRole('Administrator');
        $foreign = PayrollRun::factory()->create();
        $this->getJson("/api/v1/payroll-runs/{$foreign->id}")->assertNotFound();
    }

    public function test_the_month_end_command_drafts_a_run_for_every_company_that_lacks_one(): void
    {
        $this->employee();
        Carbon::setTestNow('2026-09-30 23:30:00');

        $this->artisan('hr:generate-payroll')->assertSuccessful();
        $this->artisan('hr:generate-payroll')->assertSuccessful();   // second run changes nothing

        $this->assertSame(1, PayrollRun::query()->where('tenant_id', $this->tenant->id)->where('period', '2026-09')->count());
    }

    public function test_the_command_can_calculate_a_past_month_and_reports_companies_with_nobody_to_pay(): void
    {
        $this->employee(['hire_date' => '2026-01-01']);

        $this->artisan('hr:generate-payroll --period=2026-08')->expectsOutputToContain('Created 1 draft payroll run(s) for 2026-08.')->assertSuccessful();
    }

    private function generate(): PayrollRun
    {
        return app(GeneratePayrollService::class)->generate($this->tenant->id, self::PERIOD);
    }

    private function slipFor(Employee $employee): Payslip
    {
        // One run per month: later calls in the same test read the slips already calculated.
        PayrollRun::query()->where('tenant_id', $this->tenant->id)->where('period', self::PERIOD)->exists() || $this->generate();

        return Payslip::query()->where('employee_id', $employee->id)->firstOrFail();
    }

    private function balance(string $code): string
    {
        return Account::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail()->balance;
    }
}

<?php

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\HR\Models\Employee;
use Modules\HR\Services\AttendanceService;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * Shared set-up for the HR tests: a tenant with the roles and ledger accounts,
 * and helpers to act as a role and to key in a month of attendance.
 */
abstract class HrTestCase extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->ensureLedgerAccounts($this->tenant);
    }

    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function employee(array $attributes = []): Employee
    {
        return Employee::factory()->recycle($this->tenant)->create($attributes);
    }

    /**
     * The working days (not Friday or Saturday) of a month, as Y-m-d strings.
     *
     * @return array<int, string>
     */
    protected function workingDays(string $month): array
    {
        $days = [];

        for ($day = Carbon::parse("{$month}-01"); $day->format('Y-m') === $month; $day->addDay()) {
            if (! in_array($day->dayOfWeek, [5, 6], true)) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    /**
     * @param  array<int, string>  $dates
     */
    protected function attend(Employee $employee, array $dates, string $in = '09:00', string $out = '17:00'): void
    {
        foreach ($dates as $date) {
            app(AttendanceService::class)->create($this->tenant->id, ['employee_id' => $employee->id, 'work_date' => $date, 'check_in' => $in, 'check_out' => $out]);
        }
    }
}

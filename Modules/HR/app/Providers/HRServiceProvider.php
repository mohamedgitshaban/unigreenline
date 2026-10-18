<?php

namespace Modules\HR\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Policies\RoutePermissionPolicy;
use Modules\HR\Console\Commands\GenerateMonthlyPayroll;
use Modules\HR\Models\Attendance;
use Modules\HR\Models\Department;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Holiday;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\PayrollRun;
use Modules\HR\Models\TimePermission;
use Modules\HR\Models\WorkSchedule;
use Nwidart\Modules\Support\ModuleServiceProvider;

class HRServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'HR';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'hr';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        GenerateMonthlyPayroll::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // Access to these is the route's permission; the policy exists so the shared
        // create/update/delete helpers have something to ask.
        foreach ([Department::class, WorkSchedule::class, Holiday::class, Employee::class, Attendance::class, TimePermission::class, LeaveRequest::class, PayrollRun::class] as $model) {
            Gate::policy($model, RoutePermissionPolicy::class);
        }
    }

    /**
     * Payroll is calculated at the end of every month: the job runs each night
     * but only does anything on the month's last day.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(GenerateMonthlyPayroll::class)->dailyAt('23:30')->when(fn () => now()->isLastOfMonth());
    }
}

<?php

namespace Modules\HR\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Dashboards\Dashboard;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Notifications\ChangeNotificationRegistry;
use Modules\Core\Notifications\WatchedEntity;
use Modules\Core\Policies\RoutePermissionPolicy;
use Modules\HR\Console\Commands\GenerateMonthlyPayroll;
use Modules\HR\Console\Commands\RemindFleet;
use Modules\HR\Dashboards\AttendanceSection;
use Modules\HR\Dashboards\FleetSection;
use Modules\HR\Dashboards\LeaveSection;
use Modules\HR\Dashboards\PayrollSection;
use Modules\HR\Dashboards\RecruitmentSection;
use Modules\HR\Dashboards\WorkforceSection;
use Modules\HR\Models\Applicant;
use Modules\HR\Models\Attendance;
use Modules\HR\Models\Department;
use Modules\HR\Models\Employee;
use Modules\HR\Models\FleetServiceType;
use Modules\HR\Models\Holiday;
use Modules\HR\Models\Interview;
use Modules\HR\Models\JobPosition;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\PayrollRun;
use Modules\HR\Models\RecruitmentStage;
use Modules\HR\Models\RefuseReason;
use Modules\HR\Models\TimePermission;
use Modules\HR\Models\Vehicle;
use Modules\HR\Models\VehicleContract;
use Modules\HR\Models\VehicleFuelLog;
use Modules\HR\Models\VehicleService;
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
        RemindFleet::class,
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
        foreach ([Department::class, WorkSchedule::class, Holiday::class, Employee::class, Attendance::class, TimePermission::class, LeaveRequest::class, PayrollRun::class, JobPosition::class, RecruitmentStage::class, RefuseReason::class, Applicant::class, Interview::class, Vehicle::class, VehicleService::class, VehicleFuelLog::class, VehicleContract::class, FleetServiceType::class] as $model) {
            Gate::policy($model, RoutePermissionPolicy::class);
        }

        $this->announceChanges(app(ChangeNotificationRegistry::class));

        $dashboards = app(DashboardRegistry::class);
        $dashboards->register(new Dashboard('hr', 'Human resources', 'hr-dashboard.view', 'People, attendance, leave and payroll.'));
        foreach ([WorkforceSection::class, AttendanceSection::class, LeaveSection::class, PayrollSection::class, RecruitmentSection::class, FleetSection::class] as $section) {
            $dashboards->section('hr', app($section));
        }
    }

    /**
     * An employee hears about their own leave and time-permission requests (and their managers do); payroll goes to whoever prepared and approved it.
     */
    private function announceChanges(ChangeNotificationRegistry $registry): void
    {
        $employeeUser = fn (array $values, ?Model $record) => [Employee::query()->whereKey($record?->employee_id ?? $values['employee_id'] ?? '')->value('user_id')];

        $registry->watch(new WatchedEntity(
            module: 'hr', entity: 'LeaveRequest', label: 'leave request', path: 'leave-requests', permission: 'leave-requests.view',
            model: LeaveRequest::class, nameAttribute: 'type', audience: $employeeUser,
        ));
        $registry->watch(new WatchedEntity(
            module: 'hr', entity: 'TimePermission', label: 'time permission', path: 'time-permissions', permission: 'time-permissions.view',
            model: TimePermission::class, nameAttribute: 'type', audience: $employeeUser,
        ));
        $registry->watch(new WatchedEntity(
            module: 'hr', entity: 'JobPosition', label: 'job position', path: 'job-positions', permission: 'job-positions.view',
            model: JobPosition::class, ownerColumns: ['recruiter_id'], notifyManagers: false,
        ));
        $registry->watch(new WatchedEntity(
            module: 'hr', entity: 'Applicant', label: 'applicant', path: 'applicants', permission: 'applicants.view',
            model: Applicant::class, ownerColumns: ['recruiter_id'], notifyManagers: false,
        ));
        $registry->watch(new WatchedEntity(
            module: 'hr', entity: 'Interview', label: 'interview', path: 'interviews', permission: 'interviews.view',
            model: Interview::class, nameAttribute: 'location', ownerColumns: ['interviewer_id'], notifyManagers: false,
        ));
        $registry->watch(new WatchedEntity(
            module: 'hr', entity: 'Vehicle', label: 'vehicle', path: 'vehicles', permission: 'vehicles.view',
            model: Vehicle::class, nameAttribute: 'license_plate', notifyManagers: false,
            audience: fn (array $values, ?Model $record) => [Employee::query()->whereKey($record?->driver_employee_id ?? $values['driver_employee_id'] ?? '')->value('user_id')],
        ));
        $registry->watch(new WatchedEntity(
            module: 'hr', entity: 'PayrollRun', label: 'payroll run', path: 'payroll-runs', permission: 'payroll-runs.view',
            model: PayrollRun::class, nameAttribute: 'period', ownerColumns: ['created_by', 'approved_by'], notifyManagers: false,
        ));
    }

    /**
     * Payroll is calculated at the end of every month: the job runs each night
     * but only does anything on the month's last day.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(GenerateMonthlyPayroll::class)->dailyAt('23:30')->when(fn () => now()->isLastOfMonth());
        $schedule->command(RemindFleet::class)->dailyAt('07:30');
    }
}

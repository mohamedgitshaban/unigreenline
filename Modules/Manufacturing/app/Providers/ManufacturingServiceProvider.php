<?php

namespace Modules\Manufacturing\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Dashboards\Dashboard;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Policies\RoutePermissionPolicy;
use Modules\Manufacturing\Console\Commands\RunMrpPlanning;
use Modules\Manufacturing\Dashboards\PlanningSection;
use Modules\Manufacturing\Dashboards\ProductionSection;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\MrpDemand;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductPlanning;
use Modules\Manufacturing\Models\QualityCheckDefinition;
use Modules\Manufacturing\Models\Routing;
use Modules\Manufacturing\Models\WorkCenter;
use Modules\Manufacturing\Policies\BomPolicy;
use Modules\Manufacturing\Policies\ProductionOrderPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ManufacturingServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Manufacturing';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'manufacturing';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        RunMrpPlanning::class,
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

        Gate::policy(Bom::class, BomPolicy::class);
        Gate::policy(ProductionOrder::class, ProductionOrderPolicy::class);

        // Access to these is the route's permission; the policy exists so the shared update/delete helpers have something to ask.
        foreach ([WorkCenter::class, Routing::class, QualityCheckDefinition::class, ProductPlanning::class, MrpDemand::class] as $model) {
            Gate::policy($model, RoutePermissionPolicy::class);
        }

        $dashboards = app(DashboardRegistry::class);
        $dashboards->register(new Dashboard('manufacturing', 'Manufacturing', 'manufacturing-dashboard.view', 'Production orders, planning and quality.'));
        $dashboards->section('manufacturing', app(ProductionSection::class));
        $dashboards->section('manufacturing', app(PlanningSection::class));
    }

    /**
     * Planning runs every night, for the default warehouse of each company, and only proposes.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(RunMrpPlanning::class)->dailyAt('02:00');
    }
}

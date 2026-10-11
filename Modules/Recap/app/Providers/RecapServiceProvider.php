<?php

namespace Modules\Recap\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Recap\Console\Commands\GenerateExpiredRecaps;
use Modules\Recap\Models\Recap;
use Modules\Recap\Policies\RecapPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class RecapServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Recap';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'recap';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        GenerateExpiredRecaps::class,
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

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(GenerateExpiredRecaps::class)->daily();
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Recap::class, RecapPolicy::class);
    }
}

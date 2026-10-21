<?php

namespace Modules\Recap\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Dashboards\Dashboard;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Notifications\ChangeNotificationRegistry;
use Modules\Core\Notifications\WatchedEntity;
use Modules\Inventory\Support\WarehouseAccess;
use Modules\Recap\Console\Commands\GenerateExpiredRecaps;
use Modules\Recap\Dashboards\WriteOffsSection;
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

        $registry = app(ChangeNotificationRegistry::class);
        $registry->watch(new WatchedEntity(
            module: 'recap', entity: 'Recap', label: 'write-off', path: 'recaps', permission: 'recaps.view',
            model: Recap::class, nameAttribute: 'reason', ownerColumns: ['created_by'],
        ));
        // The warehouse the stock is written off from hears about it too.
        $registry->alsoNotify(
            'recap', 'Recap',
            fn (array $values, ?Model $record) => app(WarehouseAccess::class)->managersOf($record?->warehouse_id ?? $values['warehouse_id'] ?? ''),
            'recaps.view',
        );

        $dashboards = app(DashboardRegistry::class);
        $dashboards->register(new Dashboard('recap', 'Write-offs', 'recap-dashboard.view', 'Stock written off and why.'));
        $dashboards->section('recap', app(WriteOffsSection::class));
    }
}

<?php

namespace Modules\Expenses\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Dashboards\Dashboard;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Notifications\ChangeNotificationRegistry;
use Modules\Core\Notifications\WatchedEntity;
use Modules\Expenses\Dashboards\ExpensesSection;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseCategory;
use Modules\Expenses\Policies\ExpenseCategoryPolicy;
use Modules\Expenses\Policies\ExpensePolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ExpensesServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Expenses';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'expenses';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

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
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(ExpenseCategory::class, ExpenseCategoryPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);

        app(ChangeNotificationRegistry::class)->watch(new WatchedEntity(
            module: 'expenses', entity: 'Expense', label: 'expense', path: 'expenses', permission: 'expenses.view',
            model: Expense::class, nameAttribute: 'reference', ownerColumns: ['created_by'],
        ));

        $dashboards = app(DashboardRegistry::class);
        $dashboards->register(new Dashboard('expenses', 'Expenses', 'expenses-dashboard.view', 'What was spent, on what, and what waits for approval.'));
        $dashboards->section('expenses', app(ExpensesSection::class));
    }
}

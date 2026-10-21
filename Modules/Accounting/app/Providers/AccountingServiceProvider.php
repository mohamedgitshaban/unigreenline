<?php

namespace Modules\Accounting\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Console\Commands\DepreciateAssets;
use Modules\Accounting\Console\Commands\RemindLoanInstallments;
use Modules\Accounting\Dashboards\AssetsSection;
use Modules\Accounting\Dashboards\BalancesSection;
use Modules\Accounting\Dashboards\LedgerSection;
use Modules\Accounting\Dashboards\LoansSection;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AssetCategory;
use Modules\Accounting\Models\FixedAsset;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\Loan;
use Modules\Accounting\Policies\AccountPolicy;
use Modules\Accounting\Policies\JournalEntryPolicy;
use Modules\Core\Dashboards\Dashboard;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Notifications\ChangeNotificationRegistry;
use Modules\Core\Notifications\WatchedEntity;
use Modules\Core\Policies\RoutePermissionPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AccountingServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Accounting';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'accounting';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        DepreciateAssets::class,
        RemindLoanInstallments::class,
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
        $schedule->command(DepreciateAssets::class)->dailyAt('02:30');
        $schedule->command(RemindLoanInstallments::class)->dailyAt('08:00');
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(JournalEntry::class, JournalEntryPolicy::class);

        $dashboards = app(DashboardRegistry::class);
        $dashboards->register(new Dashboard('accounting', 'Accounting', 'accounting-dashboard.view', 'Revenue, expenses and the financial position.'));
        $dashboards->section('accounting', app(LedgerSection::class));
        $dashboards->section('accounting', app(BalancesSection::class));
        $dashboards->section('accounting', app(AssetsSection::class));
        $dashboards->section('accounting', app(LoansSection::class));

        Gate::policy(AssetCategory::class, RoutePermissionPolicy::class);

        $registry = app(ChangeNotificationRegistry::class);
        $registry->watch(new WatchedEntity(
            module: 'accounting', entity: 'FixedAsset', label: 'fixed asset', path: 'fixed-assets', permission: 'fixed-assets.view',
            model: FixedAsset::class, ownerColumns: ['created_by', 'assigned_user_id'], notifyManagers: false,
        ));
        $registry->watch(new WatchedEntity(
            module: 'accounting', entity: 'Loan', label: 'loan', path: 'loans', permission: 'loans.view',
            model: Loan::class, ownerColumns: ['created_by'], notifyManagers: false,
        ));
    }
}

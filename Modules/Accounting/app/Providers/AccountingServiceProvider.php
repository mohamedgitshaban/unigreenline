<?php

namespace Modules\Accounting\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Policies\AccountPolicy;
use Modules\Accounting\Policies\JournalEntryPolicy;
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

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(JournalEntry::class, JournalEntryPolicy::class);
    }
}

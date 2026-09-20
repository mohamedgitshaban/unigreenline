<?php

namespace Modules\CRM\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\Complaint;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerVisit;
use Modules\CRM\Models\Lead;
use Modules\CRM\Policies\CampaignPolicy;
use Modules\CRM\Policies\ComplaintPolicy;
use Modules\CRM\Policies\CustomerPolicy;
use Modules\CRM\Policies\CustomerVisitPolicy;
use Modules\CRM\Policies\LeadPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CRMServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'CRM';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'crm';

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

        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(CustomerVisit::class, CustomerVisitPolicy::class);
        Gate::policy(Complaint::class, ComplaintPolicy::class);
        Gate::policy(Campaign::class, CampaignPolicy::class);
    }
}

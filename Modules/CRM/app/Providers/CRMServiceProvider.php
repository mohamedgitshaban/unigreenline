<?php

namespace Modules\CRM\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Dashboards\Dashboard;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Notifications\ChangeNotificationRegistry;
use Modules\Core\Notifications\WatchedEntity;
use Modules\CRM\Dashboards\CallsSection;
use Modules\CRM\Dashboards\ComplaintsSection;
use Modules\CRM\Dashboards\CustomersSection;
use Modules\CRM\Dashboards\LeadsSection;
use Modules\CRM\Dashboards\VisitsSection;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\Complaint;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerCall;
use Modules\CRM\Models\CustomerVisit;
use Modules\CRM\Models\Lead;
use Modules\CRM\Policies\CampaignPolicy;
use Modules\CRM\Policies\ComplaintPolicy;
use Modules\CRM\Policies\CustomerCallPolicy;
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
        Gate::policy(CustomerCall::class, CustomerCallPolicy::class);
        Gate::policy(Complaint::class, ComplaintPolicy::class);
        Gate::policy(Campaign::class, CampaignPolicy::class);

        $this->announceChanges(app(ChangeNotificationRegistry::class));

        $dashboards = app(DashboardRegistry::class);
        $dashboards->register(new Dashboard('crm', 'Customers', 'crm-dashboard.view', 'Customers, calls, visits, leads and complaints.'));
        foreach ([CustomersSection::class, CallsSection::class, VisitsSection::class, LeadsSection::class, ComplaintsSection::class] as $section) {
            $dashboards->section('crm', app($section));
        }
    }

    /**
     * Who hears about a change to a CRM record: whoever owns the customer (and their managers), plus whoever
     * the activity or case is assigned to.
     */
    private function announceChanges(ChangeNotificationRegistry $registry): void
    {
        $customerOwner = fn (array $values) => isset($values['customer_id'])
            ? [Customer::query()->whereKey($values['customer_id'])->value('sales_rep_id')]
            : [];

        $registry->watch(new WatchedEntity(
            module: 'crm', entity: 'Customer', label: 'customer', path: 'customers', permission: 'customers.view',
            model: Customer::class, ownerColumns: ['sales_rep_id'],
        ));
        $registry->watch(new WatchedEntity(
            module: 'crm', entity: 'CustomerVisit', label: 'visit', path: 'visits', permission: 'visits.view',
            model: CustomerVisit::class, nameAttribute: 'type', ownerColumns: ['rep_id'], audience: $customerOwner,
        ));
        $registry->watch(new WatchedEntity(
            module: 'crm', entity: 'CustomerCall', label: 'call', path: 'calls', permission: 'calls.view',
            model: CustomerCall::class, nameAttribute: 'subject', ownerColumns: ['rep_id'], audience: $customerOwner,
        ));
        $registry->watch(new WatchedEntity(
            module: 'crm', entity: 'Lead', label: 'lead', path: 'leads', permission: 'leads.view',
            model: Lead::class, ownerColumns: ['assigned_to'],
        ));
        $registry->watch(new WatchedEntity(
            module: 'crm', entity: 'Complaint', label: 'complaint', path: 'complaints', permission: 'complaints.view',
            model: Complaint::class, nameAttribute: 'type', ownerColumns: ['assigned_to'], audience: $customerOwner,
        ));
    }
}

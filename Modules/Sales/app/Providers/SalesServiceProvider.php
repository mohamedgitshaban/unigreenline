<?php

namespace Modules\Sales\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Sales\Console\Commands\FlipOverdueInvoices;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\ReturnRecord;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Policies\CollectionPolicy;
use Modules\Sales\Policies\DeliveryPolicy;
use Modules\Sales\Policies\InvoicePolicy;
use Modules\Sales\Policies\ReturnRecordPolicy;
use Modules\Sales\Policies\SalesOrderPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SalesServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Sales';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'sales';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        FlipOverdueInvoices::class,
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
        $schedule->command(FlipOverdueInvoices::class)->daily();
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(SalesOrder::class, SalesOrderPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Delivery::class, DeliveryPolicy::class);
        Gate::policy(Collection::class, CollectionPolicy::class);
        Gate::policy(ReturnRecord::class, ReturnRecordPolicy::class);
    }
}

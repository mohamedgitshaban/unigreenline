<?php

namespace Modules\Purchasing\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Dashboards\Dashboard;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Notifications\ChangeNotificationRegistry;
use Modules\Core\Notifications\WatchedEntity;
use Modules\Purchasing\Dashboards\PayablesSection;
use Modules\Purchasing\Dashboards\PurchaseOrdersSection;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierPayment;
use Modules\Purchasing\Policies\PurchaseOrderPolicy;
use Modules\Purchasing\Policies\SupplierPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PurchasingServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Purchasing';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'purchasing';

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

        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);

        $registry = app(ChangeNotificationRegistry::class);
        $registry->watch(new WatchedEntity(
            module: 'purchasing', entity: 'PurchaseOrder', label: 'purchase order', path: 'purchase-orders', permission: 'purchase-orders.view',
            model: PurchaseOrder::class, nameAttribute: 'supplier_invoice_no', ownerColumns: ['created_by'],
        ));
        $registry->watch(new WatchedEntity(
            module: 'purchasing', entity: 'SupplierPayment', label: 'supplier payment', path: 'supplier-payments', permission: 'supplier-payments.view',
            model: SupplierPayment::class, nameAttribute: 'reference', ownerColumns: ['created_by'], notifyManagers: false,
        ));

        $dashboards = app(DashboardRegistry::class);
        $dashboards->register(new Dashboard('purchasing', 'Purchasing', 'purchasing-dashboard.view', 'Purchase orders, suppliers and what is owed to them.'));
        $dashboards->section('purchasing', app(PurchaseOrdersSection::class));
        $dashboards->section('purchasing', app(PayablesSection::class));
    }
}

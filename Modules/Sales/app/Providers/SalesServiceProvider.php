<?php

namespace Modules\Sales\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Dashboards\Dashboard;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Notifications\ChangeNotificationRegistry;
use Modules\Core\Notifications\WatchedEntity;
use Modules\Inventory\Providers\InventoryServiceProvider;
use Modules\Sales\Console\Commands\FlipOverdueInvoices;
use Modules\Sales\Dashboards\DeliveriesSection;
use Modules\Sales\Dashboards\InvoicesSection;
use Modules\Sales\Dashboards\OrdersSection;
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

        $this->announceChanges(app(ChangeNotificationRegistry::class));

        $dashboards = app(DashboardRegistry::class);
        $dashboards->register(new Dashboard('sales', 'Sales', 'sales-dashboard.view', 'Orders, invoices, collections and deliveries.'));
        foreach ([OrdersSection::class, InvoicesSection::class, DeliveriesSection::class] as $section) {
            $dashboards->section('sales', app($section));
        }
    }

    /**
     * Who hears about a change to a sales document: the rep who owns the order (and their managers).
     * The warehouses involved add their managers in {@see InventoryServiceProvider}.
     */
    private function announceChanges(ChangeNotificationRegistry $registry): void
    {
        $repOfOrder = fn (?string $orderId) => $orderId ? [SalesOrder::query()->whereKey($orderId)->value('sales_rep_id')] : [];

        $registry->watch(new WatchedEntity(
            module: 'sales', entity: 'SalesOrder', label: 'sales order', path: 'sales-orders', permission: 'sales-orders.view',
            model: SalesOrder::class, nameAttribute: 'id', ownerColumns: ['sales_rep_id'],
        ));
        $registry->watch(new WatchedEntity(
            module: 'sales', entity: 'Invoice', label: 'invoice', path: 'invoices', permission: 'invoices.view',
            model: Invoice::class, nameAttribute: 'id', audience: fn (array $values, ?Model $record) => $repOfOrder($record?->so_id ?? $values['so_id'] ?? null),
        ));
        $registry->watch(new WatchedEntity(
            module: 'sales', entity: 'Collection', label: 'collection', path: 'collections', permission: 'collections.view',
            model: Collection::class, nameAttribute: 'reference',
            audience: fn (array $values, ?Model $record) => $repOfOrder(Invoice::query()->whereKey($record?->invoice_id ?? $values['invoice_id'] ?? null)->value('so_id')),
        ));
        $registry->watch(new WatchedEntity(
            module: 'sales', entity: 'Delivery', label: 'delivery', path: 'deliveries', permission: 'deliveries.view',
            model: Delivery::class, nameAttribute: 'driver', audience: fn (array $values, ?Model $record) => $repOfOrder($record?->so_id ?? $values['so_id'] ?? null),
        ));
    }
}

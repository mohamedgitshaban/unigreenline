<?php

namespace Modules\Inventory\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Dashboards\Dashboard;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Notifications\ChangeNotificationRegistry;
use Modules\Core\Notifications\WatchedEntity;
use Modules\Inventory\Console\Commands\GenerateExpiryAlerts;
use Modules\Inventory\Dashboards\FlowSection;
use Modules\Inventory\Dashboards\MovementsSection;
use Modules\Inventory\Dashboards\StockSection;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Observers\InventoryBatchObserver;
use Modules\Inventory\Policies\ProductCategoryPolicy;
use Modules\Inventory\Policies\ProductPolicy;
use Modules\Inventory\Policies\TransferPolicy;
use Modules\Inventory\Policies\UnitPolicy;
use Modules\Inventory\Policies\WarehousePolicy;
use Modules\Inventory\Support\WarehouseAccess;
use Nwidart\Modules\Support\ModuleServiceProvider;

class InventoryServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Inventory';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'inventory';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        GenerateExpiryAlerts::class,
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
        $schedule->command(GenerateExpiryAlerts::class)->daily();
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Unit::class, UnitPolicy::class);
        Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
        Gate::policy(Transfer::class, TransferPolicy::class);

        InventoryBatch::observe(InventoryBatchObserver::class);

        $this->announceChanges(app(ChangeNotificationRegistry::class));

        $dashboards = app(DashboardRegistry::class);
        $dashboards->register(new Dashboard('inventory', 'Inventory', 'inventory-dashboard.view', 'Stock, movements, and what is coming in and going out.'));
        foreach ([StockSection::class, MovementsSection::class, FlowSection::class] as $section) {
            $dashboards->section('inventory', app($section));
        }
    }

    /**
     * Warehouses and transfers announce their changes to the people who run the warehouses involved, and the
     * warehouse manager is also told about the purchase orders on their way in and the sales orders due to go out.
     */
    private function announceChanges(ChangeNotificationRegistry $registry): void
    {
        $managers = fn (?string ...$warehouseIds) => collect($warehouseIds)->filter()->flatMap(fn (string $id) => app(WarehouseAccess::class)->managersOf($id))->unique()->values()->all();

        $registry->watch(new WatchedEntity(
            module: 'inventory', entity: 'Warehouse', label: 'warehouse', path: 'warehouses', permission: 'warehouses.view',
            model: Warehouse::class, ownerColumns: ['manager_id'],
        ));
        $registry->watch(new WatchedEntity(
            module: 'inventory', entity: 'Transfer', label: 'transfer', path: 'transfers', permission: 'transfers.view',
            model: Transfer::class, nameAttribute: 'batch_no',
            audience: fn (array $values, ?Model $record) => $managers($record?->from_warehouse_id ?? $values['from_warehouse_id'] ?? null, $record?->to_warehouse_id ?? $values['to_warehouse_id'] ?? null),
        ));

        $registry->alsoNotify(
            'purchasing', 'PurchaseOrder',
            fn (array $values, ?Model $record) => $managers($record?->warehouse_id ?? $values['warehouse_id'] ?? null),
            'warehouses.incoming',
            fn (array $values, ?Model $record, string $id) => ($warehouse = $record?->warehouse_id ?? $values['warehouse_id'] ?? null) ? "/api/v1/warehouses/{$warehouse}/incoming?purchase_order_id={$id}" : null,
        );

        $warehousesOfOrder = fn (array $values, ?Model $record) => collect([$record?->warehouse_id ?? $values['warehouse_id'] ?? null])
            ->merge($record ? DB::table('sales_order_lines')->where('so_id', $record->getKey())->whereNotNull('warehouse_id')->distinct()->pluck('warehouse_id') : [])
            ->filter()->unique()->values()->all();

        $registry->alsoNotify(
            'sales', 'SalesOrder',
            fn (array $values, ?Model $record) => $managers(...$warehousesOfOrder($values, $record)),
            'warehouses.outgoing',
            fn (array $values, ?Model $record, string $id) => ($warehouse = $warehousesOfOrder($values, $record)[0] ?? null) ? "/api/v1/warehouses/{$warehouse}/outgoing?sales_order_id={$id}" : null,
        );
    }
}

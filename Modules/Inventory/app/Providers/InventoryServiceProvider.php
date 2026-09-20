<?php

namespace Modules\Inventory\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Console\Commands\GenerateExpiryAlerts;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Policies\ProductCategoryPolicy;
use Modules\Inventory\Policies\ProductPolicy;
use Modules\Inventory\Policies\TransferPolicy;
use Modules\Inventory\Policies\WarehousePolicy;
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
        Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
        Gate::policy(Transfer::class, TransferPolicy::class);
    }
}

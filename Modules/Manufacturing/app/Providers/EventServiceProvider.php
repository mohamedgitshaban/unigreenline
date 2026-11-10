<?php

namespace Modules\Manufacturing\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Inventory\Events\StockRequestGranted;
use Modules\Manufacturing\Events\ManufacturingNotifiable;
use Modules\Manufacturing\Listeners\CreateManufacturingNotifications;
use Modules\Manufacturing\Listeners\CreateProductionOrderFromStockRequest;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        StockRequestGranted::class => [CreateProductionOrderFromStockRequest::class],
        ManufacturingNotifiable::class => [CreateManufacturingNotifications::class],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = false;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}

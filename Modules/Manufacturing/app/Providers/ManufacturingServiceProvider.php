<?php

namespace Modules\Manufacturing\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Policies\BomPolicy;
use Modules\Manufacturing\Policies\ProductionOrderPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ManufacturingServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Manufacturing';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'manufacturing';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Bom::class, BomPolicy::class);
        Gate::policy(ProductionOrder::class, ProductionOrderPolicy::class);
    }
}

<?php

namespace Modules\Core\Providers;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Core\Console\Commands\VerifyAuditChain;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Nwidart\Modules\Support\ModuleServiceProvider;
use RuntimeException;

class CoreServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Core';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'core';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        VerifyAuditChain::class,
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
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(AuditLogService::class, function () {
            $hmacKey = config('audit.hmac_key');

            if (blank($hmacKey)) {
                throw new RuntimeException(
                    'AUDIT_HMAC_SECRET is not set. The audit log hash chain cannot be computed without it.'
                );
            }

            return new AuditLogService($hmacKey);
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Pure JSON API — there is no web login page to redirect guests to.
        Authenticate::redirectUsing(fn () => null);

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(Str::transliterate(
                Str::lower($request->string('email')).'|'.$request->ip()
            ));
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        Gate::before(fn (User $user) => $user->hasRole('Administrator') ? true : null);
    }
}

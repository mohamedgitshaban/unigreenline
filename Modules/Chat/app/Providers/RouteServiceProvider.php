<?php

namespace Modules\Chat\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\MessageAttachment;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Chat';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        parent::boot();

        // Conversations and their files are private to the people in them. Binding the route
        // parameters to the caller's own records (by scope, not by policy, which Administrator's
        // `Gate::before` bypass would skip) turns anyone else's id into a plain 404.
        Route::bind('conversation', fn (string $id) => Conversation::query()
            ->forParticipant(request()->user())
            ->findOrFail($id));

        Route::bind('messageAttachment', fn (string $id) => MessageAttachment::query()
            ->whereHas('message.conversation', fn (Builder $conversations) => $conversations->forParticipant(request()->user()))
            ->findOrFail($id));
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapApiRoutes();
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));
    }
}

<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // A database error carries the host, the database name and the SQL. Show that only to a developer
        // on this machine; everyone else gets a short message and a reference to find it in the log.
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->is('api/*') || (config('app.debug') && in_array($request->ip(), ['127.0.0.1', '::1'], true))) {
                return null;
            }

            $reference = (string) Str::ulid();
            Context::add('error_reference', $reference);

            return response()->json([
                'message' => 'Something went wrong. Please try again, and give this reference to support if it keeps happening.',
                'reference' => $reference,
            ], 500);
        });
    })->create();

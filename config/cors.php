<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | Spec §9.9: the prototype reflected any Origin header with credentials
    | allowed — overly permissive. This API is Bearer-token auth (Sanctum
    | personal access tokens), not cookie-based, so `supports_credentials`
    | below stays false regardless. `allowed_origins` still needs locking
    | down to the real frontend origin(s) so a browser can't be tricked into
    | reading responses cross-origin. Set FRONTEND_URLS (comma-separated) in
    | .env for any non-local/testing environment; outside those environments
    | an unset FRONTEND_URLS means no origin is allowed, not a wildcard.
    */
    'allowed_origins' => empty(env('FRONTEND_URLS'))
        ? (in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) ? ['*'] : [])
        : array_map('trim', explode(',', env('FRONTEND_URLS'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

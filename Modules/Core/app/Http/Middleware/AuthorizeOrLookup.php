<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `->canOrLookup('products.view')` on a list route: the pick-list behind a form's dropdown.
 *
 * A user who holds the permission gets the list exactly as `->can()` would give it. Any other signed-in
 * user is let through too, but marked as lookup-only, and the controller then answers with just the
 * `id` and `name` of each record (see ServesLookups) — enough to fill a dropdown, nothing more.
 * Everything else on the resource (one record, its nested lists, writes) keeps its plain `->can()`.
 *
 * The permission is still the one the catalogue lists for the route, so roles are edited as before.
 */
class AuthorizeOrLookup
{
    private const ATTRIBUTE = 'lookup_only';

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $request->attributes->set(self::ATTRIBUTE, ! $request->user()->can($ability));

        return $next($request);
    }

    public static function isLookupOnly(Request $request): bool
    {
        return $request->attributes->get(self::ATTRIBUTE, false) === true;
    }
}

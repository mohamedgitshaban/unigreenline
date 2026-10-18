<?php

namespace Modules\Core\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * The list of every permission in the system, read straight off the routes.
 *
 * Each API endpoint is guarded by exactly one permission, declared on the
 * route itself (`->can('expenses.view')`), named `{resource}.{action}`. List
 * endpoints that can also return a file declare a second one for that
 * (`->exportable('expenses.export')`). Reading the catalogue from the routes
 * means it can never drift from what is actually enforced: a permission that
 * appears here is checked by an endpoint, and an endpoint without one is
 * caught by the test that walks every route.
 */
class PermissionCatalogue
{
    /**
     * Controller module => the permission group shown to admins.
     *
     * @var array<string, string>
     */
    private const MODULE_GROUPS = ['Core' => 'admin', 'CRM' => 'crm', 'HR' => 'hr'];

    /**
     * Permissions whose natural group differs from their controller's module.
     *
     * @var array<string, string>
     */
    private const GROUP_OVERRIDES = ['products.lifecycle' => 'inventory'];

    /**
     * Every permission with the endpoints it guards.
     *
     * @return Collection<string, array{permission: string, resource: string, action: string, module: string, endpoints: array<int, string>}>
     */
    public static function all(): Collection
    {
        $entries = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $endpoint = collect($route->methods())->reject(fn (string $m) => $m === 'HEAD')->first().' /'.$route->uri();

            foreach (self::abilitiesOf($route) as $ability) {
                $entries[$ability] ??= self::describe($ability, $route);
                $entries[$ability]['endpoints'][] = $endpoint;
            }
        }

        ksort($entries);

        return collect($entries);
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return self::all()->keys()->all();
    }

    /**
     * Permissions grouped for a role form: module => resource => [permission names].
     *
     * @return array<string, array<string, array<int, string>>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::all() as $entry) {
            $grouped[$entry['module']][$entry['resource']][] = $entry['permission'];
        }

        ksort($grouped);

        return array_map(function (array $resources) {
            ksort($resources);

            return $resources;
        }, $grouped);
    }

    /**
     * The `can:` ability and the `export_permission` an API route declares.
     *
     * @return array<int, string>
     */
    public static function abilitiesOf(Route $route): array
    {
        $abilities = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                $abilities[] = explode(',', substr($middleware, 4))[0];
            }
        }

        if (($export = $route->getAction('export_permission')) !== null) {
            $abilities[] = $export;
        }

        return $abilities;
    }

    /**
     * @return array{permission: string, resource: string, action: string, module: string, endpoints: array<int, string>}
     */
    private static function describe(string $ability, Route $route): array
    {
        [$resource, $action] = array_pad(explode('.', $ability, 2), 2, '');

        return [
            'permission' => $ability,
            'resource' => $resource,
            'action' => $action,
            'module' => self::GROUP_OVERRIDES[$ability] ?? self::moduleOf($route),
            'endpoints' => [],
        ];
    }

    private static function moduleOf(Route $route): string
    {
        preg_match('/^Modules\\\\(\w+)\\\\/', ltrim($route->getActionName(), '\\'), $match);
        $module = $match[1] ?? 'Core';

        return self::MODULE_GROUPS[$module] ?? strtolower($module);
    }
}

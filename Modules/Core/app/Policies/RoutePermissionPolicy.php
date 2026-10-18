<?php

namespace Modules\Core\Policies;

/**
 * For models whose only access rule is the permission their route already
 * demands (`{resource}.{action}`, see PermissionCatalogue). Controllers still
 * call `authorize()` on them through the shared create/update/delete helpers,
 * and without a policy Laravel would refuse every non-administrator; this one
 * says yes, because the route has already checked.
 */
class RoutePermissionPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return true;
    }
}

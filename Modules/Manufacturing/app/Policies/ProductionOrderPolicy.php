<?php

namespace Modules\Manufacturing\Policies;

use Modules\Core\Models\User;

/**
 * Tenant scoping is enforced in the controller and form requests, not here:
 * Administrator's Gate::before bypass would otherwise let it cross tenants.
 */
class ProductionOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user): bool
    {
        return true;
    }

    public function delete(User $user): bool
    {
        return true;
    }

    /**
     * Completing consumes stock and posts to the ledger.
     */
    public function approve(User $user): bool
    {
        return true;
    }
}

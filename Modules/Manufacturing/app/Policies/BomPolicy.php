<?php

namespace Modules\Manufacturing\Policies;

use Modules\Core\Models\User;

/**
 * Tenant scoping is enforced in the controller and form requests, not here:
 * Administrator's Gate::before bypass would otherwise let it cross tenants.
 */
class BomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manufacturing.view');
    }

    public function view(User $user): bool
    {
        return $user->can('manufacturing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('manufacturing.add');
    }

    public function update(User $user): bool
    {
        return $user->can('manufacturing.edit');
    }

    public function delete(User $user): bool
    {
        return $user->can('manufacturing.delete');
    }
}

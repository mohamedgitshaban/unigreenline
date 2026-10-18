<?php

namespace Modules\Inventory\Policies;

use Modules\Core\Models\User;

/**
 * Tenant scoping is enforced in the controller, not here.
 */
class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
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
}

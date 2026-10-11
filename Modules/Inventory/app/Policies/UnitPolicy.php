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
        return $user->canAny(['inventory.view', 'purchasing.view', 'sales.view']);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.add');
    }

    public function update(User $user): bool
    {
        return $user->can('inventory.edit');
    }

    public function delete(User $user): bool
    {
        return $user->can('inventory.delete');
    }
}

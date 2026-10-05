<?php

namespace Modules\Inventory\Policies;

use Modules\Core\Models\User;

class TransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view');
    }

    /**
     * Tenant scoping is enforced in the controller, not here.
     */
    public function view(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.add');
    }
}

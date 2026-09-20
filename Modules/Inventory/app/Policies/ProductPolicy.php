<?php

namespace Modules\Inventory\Policies;

use Modules\Core\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function view(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.add');
    }

    public function update(User $user): bool
    {
        return $user->can('inventory.edit');
    }
}

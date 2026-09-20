<?php

namespace Modules\Inventory\Policies;

use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;

class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $user->can('inventory.view') && $warehouse->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.add');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $user->can('inventory.edit') && $warehouse->isVisibleTo($user);
    }
}

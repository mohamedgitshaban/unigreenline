<?php

namespace Modules\Inventory\Policies;

use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;

class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $warehouse->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $warehouse->isVisibleTo($user);
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return $warehouse->isVisibleTo($user);
    }
}

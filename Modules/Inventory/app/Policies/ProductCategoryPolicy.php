<?php

namespace Modules\Inventory\Policies;

use Modules\Core\Models\User;

class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.add');
    }
}

<?php

namespace Modules\Inventory\Policies;

use Modules\Core\Models\User;
use Modules\Inventory\Models\ProductCategory;

class ProductCategoryPolicy
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

    public function update(User $user, ProductCategory $category): bool
    {
        return $user->can('inventory.edit') && $category->tenant_id === $user->tenant_id;
    }

    public function delete(User $user): bool
    {
        return $user->can('inventory.delete');
    }
}

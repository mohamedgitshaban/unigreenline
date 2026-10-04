<?php

namespace Modules\Expenses\Policies;

use Modules\Core\Models\User;

class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('expenses.view');
    }

    public function create(User $user): bool
    {
        return $user->can('expenses.add');
    }

    /**
     * Tenant scoping is enforced in UpdateExpenseCategoryRequest, not here.
     */
    public function update(User $user): bool
    {
        return $user->can('expenses.edit');
    }
}

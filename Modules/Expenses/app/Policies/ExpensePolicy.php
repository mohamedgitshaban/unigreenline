<?php

namespace Modules\Expenses\Policies;

use Modules\Core\Models\User;

/**
 * Tenant scoping is enforced in the controller and form requests, not here:
 * Administrator's Gate::before bypass would otherwise let it cross tenants.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('expenses.view');
    }

    public function view(User $user): bool
    {
        return $user->can('expenses.view');
    }

    public function create(User $user): bool
    {
        return $user->can('expenses.add');
    }

    public function update(User $user): bool
    {
        return $user->can('expenses.edit');
    }

    public function delete(User $user): bool
    {
        return $user->can('expenses.delete');
    }

    /**
     * Covers both approve and reject.
     */
    public function approve(User $user): bool
    {
        return $user->can('expenses.approve');
    }
}

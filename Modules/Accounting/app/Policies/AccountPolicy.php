<?php

namespace Modules\Accounting\Policies;

use Modules\Core\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.view');
    }

    /**
     * Tenant scoping is enforced in the controller, not here.
     */
    public function view(User $user): bool
    {
        return $user->can('accounting.view');
    }

    public function create(User $user): bool
    {
        return $user->can('accounting.add');
    }

    public function update(User $user): bool
    {
        return $user->can('accounting.edit');
    }

    public function delete(User $user): bool
    {
        return $user->can('accounting.delete');
    }
}

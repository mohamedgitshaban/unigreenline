<?php

namespace Modules\CRM\Policies;

use Modules\Core\Models\User;

class ComplaintPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.view');
    }

    /**
     * Tenant scoping is enforced in the controller, not here.
     */
    public function view(User $user): bool
    {
        return $user->can('crm.view');
    }

    public function create(User $user): bool
    {
        return $user->can('crm.add');
    }

    public function resolve(User $user): bool
    {
        return $user->can('crm.edit');
    }

    public function update(User $user): bool
    {
        return $user->can('crm.edit');
    }

    public function delete(User $user): bool
    {
        return $user->can('crm.delete');
    }
}

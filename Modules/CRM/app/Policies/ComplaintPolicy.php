<?php

namespace Modules\CRM\Policies;

use Modules\Core\Models\User;

class ComplaintPolicy
{
    public function viewAny(User $user): bool
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
}

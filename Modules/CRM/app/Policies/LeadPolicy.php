<?php

namespace Modules\CRM\Policies;

use Modules\Core\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.view');
    }

    public function create(User $user): bool
    {
        return $user->can('crm.add');
    }
}

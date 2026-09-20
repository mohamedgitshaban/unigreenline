<?php

namespace Modules\Accounting\Policies;

use Modules\Core\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.view');
    }
}

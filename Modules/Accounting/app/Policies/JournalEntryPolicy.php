<?php

namespace Modules\Accounting\Policies;

use Modules\Core\Models\User;

class JournalEntryPolicy
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
}

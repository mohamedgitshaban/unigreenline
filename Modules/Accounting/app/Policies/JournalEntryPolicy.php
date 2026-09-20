<?php

namespace Modules\Accounting\Policies;

use Modules\Core\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.view');
    }
}

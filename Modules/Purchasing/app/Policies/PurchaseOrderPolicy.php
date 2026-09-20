<?php

namespace Modules\Purchasing\Policies;

use Modules\Core\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.view');
    }

    public function view(User $user): bool
    {
        return $user->can('purchasing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.add');
    }

    /**
     * Requires Approve permission (spec §5.2, §6).
     */
    public function receive(User $user): bool
    {
        return $user->can('purchasing.approve');
    }
}

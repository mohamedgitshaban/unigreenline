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

    /**
     * Tenant scoping is enforced in UpdatePurchaseOrderRequest, not here.
     */
    public function update(User $user): bool
    {
        return $user->can('purchasing.edit');
    }

    /**
     * Tenant scoping for delete is enforced in the controller, not here —
     * see PurchaseOrderController::destroy().
     */
    public function delete(User $user): bool
    {
        return $user->can('purchasing.delete');
    }
}

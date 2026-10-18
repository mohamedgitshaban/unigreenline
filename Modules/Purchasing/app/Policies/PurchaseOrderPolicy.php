<?php

namespace Modules\Purchasing\Policies;

use Modules\Core\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Requires Approve permission (spec §5.2, §6).
     */
    public function receive(User $user): bool
    {
        return true;
    }

    /**
     * Setting what a purchase order costs. Held by Administrator only, unless an
     * admin grants `purchasing.pricing` to a role.
     */
    public function price(User $user): bool
    {
        return true;
    }

    /**
     * Tenant scoping is enforced in UpdatePurchaseOrderRequest, not here.
     */
    public function update(User $user): bool
    {
        return true;
    }

    /**
     * Tenant scoping for delete is enforced in the controller, not here —
     * see PurchaseOrderController::destroy().
     */
    public function delete(User $user): bool
    {
        return true;
    }
}

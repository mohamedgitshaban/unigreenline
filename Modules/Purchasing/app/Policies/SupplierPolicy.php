<?php

namespace Modules\Purchasing\Policies;

use Modules\Core\Models\User;
use Modules\Purchasing\Models\Supplier;

/**
 * AP aging reads this same viewAny ability — Accountant (accounting.view,
 * no purchasing.* per spec §2) needs to see supplier balances too, same
 * reasoning as Invoice/Collection accepting accounting.* alongside sales.*.
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Tenant scoping is enforced in the controller, not here.
     */
    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $supplier->tenant_id === $user->tenant_id;
    }

    public function delete(User $user): bool
    {
        return true;
    }
}

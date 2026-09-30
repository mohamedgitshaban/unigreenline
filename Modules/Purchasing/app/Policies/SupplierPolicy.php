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
        return $user->can('purchasing.view') || $user->can('accounting.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.add');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('purchasing.edit') && $supplier->tenant_id === $user->tenant_id;
    }
}

<?php

namespace Modules\CRM\Policies;

use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;

/**
 * Sales Rep ownership scoping (spec §2: "Own orders/customers only").
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {

        if ($user->hasRole('Sales Rep')) {
            return $customer->sales_rep_id === $user->id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): bool
    {

        if ($user->hasRole('Sales Rep')) {
            return $customer->sales_rep_id === $user->id;
        }

        return true;
    }

    public function delete(User $user, Customer $customer): bool
    {
        return ! $user->hasRole('Sales Rep') || $customer->sales_rep_id === $user->id;
    }
}

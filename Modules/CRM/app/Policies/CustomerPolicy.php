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
        return $user->can('crm.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        if (! $user->can('crm.view')) {
            return false;
        }

        if ($user->hasRole('Sales Rep')) {
            return $customer->sales_rep_id === $user->id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('crm.add');
    }

    public function update(User $user, Customer $customer): bool
    {
        if (! $user->can('crm.edit')) {
            return false;
        }

        if ($user->hasRole('Sales Rep')) {
            return $customer->sales_rep_id === $user->id;
        }

        return true;
    }
}

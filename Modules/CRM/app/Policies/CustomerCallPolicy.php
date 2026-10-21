<?php

namespace Modules\CRM\Policies;

use Modules\Core\Models\User;
use Modules\CRM\Models\CustomerCall;

/**
 * Calls are visible to whoever logged them, their managers up the reporting line, and whoever owns the customer.
 * Tenant scoping is enforced in the controller.
 */
class CustomerCallPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CustomerCall $call): bool
    {
        return $call->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CustomerCall $call): bool
    {
        return $call->isVisibleTo($user);
    }

    public function delete(User $user, CustomerCall $call): bool
    {
        return $call->isVisibleTo($user);
    }
}

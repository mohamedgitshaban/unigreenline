<?php

namespace Modules\CRM\Policies;

use Modules\Core\Models\User;
use Modules\CRM\Models\CustomerVisit;

/**
 * Visits are visible to whoever logged them, their managers up the reporting line, and whoever owns the customer.
 * Tenant scoping is enforced in the controller.
 */
class CustomerVisitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CustomerVisit $visit): bool
    {
        return $visit->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CustomerVisit $visit): bool
    {
        return $visit->isVisibleTo($user);
    }

    public function delete(User $user, CustomerVisit $visit): bool
    {
        return $visit->isVisibleTo($user);
    }
}

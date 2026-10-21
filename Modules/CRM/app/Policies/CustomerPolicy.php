<?php

namespace Modules\CRM\Policies;

use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\Customer;

/**
 * Ownership scoping (spec §2: "Own orders/customers only"), extended down the reporting line:
 * a user whose scope is `team` (a Sales Rep by default) sees and changes the customers
 * of themselves and of everyone who reports to them. See {@see DataScope}.
 */
class CustomerPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->scope->canSee($user, $customer->sales_rep_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer);
    }

    public function assign(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer);
    }
}

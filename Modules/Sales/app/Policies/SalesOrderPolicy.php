<?php

namespace Modules\Sales\Policies;

use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\Sales\Models\SalesOrder;

class SalesOrderPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SalesOrder $salesOrder): bool
    {
        return $this->scope->canSee($user, $salesOrder->sales_rep_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function updateStatus(User $user, SalesOrder $salesOrder): bool
    {
        return $this->view($user, $salesOrder);
    }

    public function update(User $user, SalesOrder $salesOrder): bool
    {
        return $this->updateStatus($user, $salesOrder);
    }

    public function delete(User $user, SalesOrder $salesOrder): bool
    {
        return $this->view($user, $salesOrder);
    }
}

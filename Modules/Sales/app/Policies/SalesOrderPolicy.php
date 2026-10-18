<?php

namespace Modules\Sales\Policies;

use Modules\Core\Models\User;
use Modules\Sales\Models\SalesOrder;

class SalesOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SalesOrder $salesOrder): bool
    {

        if ($user->hasRole('Sales Rep')) {
            return $salesOrder->sales_rep_id === $user->id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function updateStatus(User $user, SalesOrder $salesOrder): bool
    {

        if ($user->hasRole('Sales Rep')) {
            return $salesOrder->sales_rep_id === $user->id;
        }

        return true;
    }

    public function update(User $user, SalesOrder $salesOrder): bool
    {
        return $this->updateStatus($user, $salesOrder);
    }

    public function delete(User $user, SalesOrder $salesOrder): bool
    {
        return ! $user->hasRole('Sales Rep') || $salesOrder->sales_rep_id === $user->id;
    }
}

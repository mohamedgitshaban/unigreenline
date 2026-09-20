<?php

namespace Modules\Sales\Policies;

use Modules\Core\Models\User;
use Modules\Sales\Models\SalesOrder;

class SalesOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.view');
    }

    public function view(User $user, SalesOrder $salesOrder): bool
    {
        if (! $user->can('sales.view')) {
            return false;
        }

        if ($user->hasRole('Sales Rep')) {
            return $salesOrder->sales_rep_id === $user->id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('sales.add');
    }

    public function updateStatus(User $user, SalesOrder $salesOrder): bool
    {
        if (! $user->can('sales.edit')) {
            return false;
        }

        if ($user->hasRole('Sales Rep')) {
            return $salesOrder->sales_rep_id === $user->id;
        }

        return true;
    }
}

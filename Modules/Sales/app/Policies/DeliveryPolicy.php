<?php

namespace Modules\Sales\Policies;

use Modules\Core\Models\User;
use Modules\Sales\Models\Delivery;

class DeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.view');
    }

    public function view(User $user, Delivery $delivery): bool
    {
        if (! $user->can('sales.view')) {
            return false;
        }

        if ($user->hasRole('Sales Rep')) {
            return $delivery->salesOrder?->sales_rep_id === $user->id;
        }

        return true;
    }

    public function update(User $user, Delivery $delivery): bool
    {
        return $this->view($user, $delivery) && $user->can('sales.edit');
    }
}

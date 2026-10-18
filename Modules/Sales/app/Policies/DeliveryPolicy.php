<?php

namespace Modules\Sales\Policies;

use Modules\Core\Models\User;
use Modules\Sales\Models\Delivery;

class DeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Delivery $delivery): bool
    {

        if ($user->hasRole('Sales Rep')) {
            return $delivery->salesOrder?->sales_rep_id === $user->id;
        }

        return true;
    }

    public function update(User $user, Delivery $delivery): bool
    {
        return $this->view($user, $delivery);
    }
}

<?php

namespace Modules\Sales\Policies;

use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\Sales\Models\Delivery;

class DeliveryPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Delivery $delivery): bool
    {
        return $this->scope->canSee($user, $delivery->salesOrder?->sales_rep_id);
    }

    public function update(User $user, Delivery $delivery): bool
    {
        return $this->view($user, $delivery);
    }
}

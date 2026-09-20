<?php

namespace Modules\Sales\Policies;

use Modules\Core\Models\User;
use Modules\Sales\Models\Collection;

/**
 * Recording a payment is an Accountant's job per spec §2's role table
 * (Accountant: accounting.add, no sales.* at all) — accounting.add must
 * be enough to create a collection, not just sales.add.
 */
class CollectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.view') || $user->can('accounting.view');
    }

    public function view(User $user, Collection $collection): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->hasRole('Sales Rep')) {
            return $collection->invoice->salesOrder?->sales_rep_id === $user->id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('sales.add') || $user->can('accounting.add');
    }
}

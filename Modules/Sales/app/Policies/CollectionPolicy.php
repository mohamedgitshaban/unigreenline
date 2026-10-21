<?php

namespace Modules\Sales\Policies;

use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\Sales\Models\Collection;

/**
 * Recording a payment is an Accountant's job per spec §2's role table
 * (Accountant: accounting.add, no sales.* at all) — accounting.add must
 * be enough to create a collection, not just sales.add.
 */
class CollectionPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Collection $collection): bool
    {
        return $this->scope->canSee($user, $collection->invoice->salesOrder?->sales_rep_id);
    }

    public function create(User $user): bool
    {
        return true;
    }
}

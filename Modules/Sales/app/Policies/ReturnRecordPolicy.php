<?php

namespace Modules\Sales\Policies;

use Modules\Core\Models\User;

/**
 * Returns cover both directions (spec §5.9): customer-facing returns are a
 * Sales concern, but "Purchase Return" (going back to a supplier) is
 * squarely Purchasing's job — the Purchasing role has no sales.* at all,
 * so either permission must be accepted here, not just sales.*.
 */
class ReturnRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.view') || $user->can('purchasing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.add') || $user->can('purchasing.add');
    }
}

<?php

namespace Modules\CRM\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;

/**
 * For records a rep logs against a customer (visits, calls): a user whose data scope is `team` sees what
 * they and their team logged, plus everything logged against customers they or their team own — so the
 * history stays with the customer when it is handed to someone else. Everyone else sees all of it.
 */
trait VisibleToTeam
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): Builder
    {
        $visible = app(DataScope::class)->visibleUserIds($user);

        if ($visible === null) {
            return $query;
        }

        return $query->where(fn (Builder $inner) => $inner
            ->whereIn($inner->qualifyColumn('rep_id'), $visible)
            ->orWhereHas('customer', fn (Builder $customer) => $customer->whereIn('sales_rep_id', $visible)));
    }

    public function isVisibleTo(User $user): bool
    {
        $scope = app(DataScope::class);

        return $scope->canSee($user, $this->rep_id) || $scope->canSee($user, $this->customer?->sales_rep_id);
    }
}

<?php

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

/**
 * Whose records a user may see: their own and everyone's below them in the reporting line.
 *
 * Each user has a manager (`users.manager_id`), which makes a tree. A user's *scope* is either
 *  - `all`: everything their permissions allow in the company (the default for every role but Sales Rep), or
 *  - `team`: only records owned by themselves or by anyone who reports to them, directly or through others.
 * `users.data_scope` sets it per person; left empty it follows the role (a Sales Rep is `team`, which is what
 * "own customers and orders only" has always meant — a rep with nobody under them still sees just their own).
 *
 * "Owned by" is a column on the record (the customer's `sales_rep_id`, a visit's `rep_id`, ...). Records
 * with no owner are visible only to users whose scope is `all`.
 *
 * The reporting tree is read once per request (the instance is scoped to the request) and forgotten
 * whenever a user is saved or deleted.
 */
class DataScope
{
    /** @var array<string, array<string, ?string>> tenant id => (user id => manager id) */
    private array $trees = [];

    public function effective(User $user): string
    {
        return $user->data_scope ?? ($user->hasRole('Sales Rep') ? 'team' : 'all');
    }

    public function isTeamScoped(User $user): bool
    {
        return $this->effective($user) === 'team';
    }

    /**
     * Everyone below the user in the reporting line (not the user), nearest first.
     *
     * @return array<int, string>
     */
    public function teamIds(User $user): array
    {
        $tree = $this->tree($user->tenant_id);
        $team = [];
        $frontier = [$user->id];

        while ($frontier !== []) {
            $next = [];

            foreach ($tree as $id => $managerId) {
                if ($managerId !== null && in_array($managerId, $frontier, true) && ! in_array($id, $team, true) && $id !== $user->id) {
                    $team[] = $id;
                    $next[] = $id;
                }
            }

            $frontier = $next;
        }

        return $team;
    }

    /**
     * The user ids whose records the user may see, or null when there is no restriction.
     *
     * @return array<int, string>|null
     */
    public function visibleUserIds(User $user): ?array
    {
        return $this->isTeamScoped($user) ? [$user->id, ...$this->teamIds($user)] : null;
    }

    public function canSee(User $viewer, ?string $ownerId): bool
    {
        $visible = $this->visibleUserIds($viewer);

        return $visible === null || ($ownerId !== null && in_array($ownerId, $visible, true));
    }

    /**
     * Restricts a query to the records the viewer may see.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $viewer, string $ownerColumn): Builder
    {
        $visible = $this->visibleUserIds($viewer);

        return $visible === null ? $query : $query->whereIn($query->qualifyColumn($ownerColumn), $visible);
    }

    /**
     * Like constrain(), for a record whose owner sits on a related record (an invoice's sales order).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrainThrough(Builder $query, User $viewer, string $relation, string $ownerColumn): Builder
    {
        $visible = $this->visibleUserIds($viewer);

        return $visible === null ? $query : $query->whereHas($relation, fn (Builder $related) => $related->whereIn($related->qualifyColumn($ownerColumn), $visible));
    }

    /**
     * Whether the actor may hand records to `$targetId`: anyone in the company for a user who sees everything,
     * otherwise themselves or someone on their team.
     */
    public function canAssignTo(User $actor, string $targetId): bool
    {
        if (! array_key_exists($targetId, $this->tree($actor->tenant_id))) {
            return false;
        }

        return $this->visibleUserIds($actor) === null || in_array($targetId, $this->visibleUserIds($actor), true);
    }

    /**
     * Everyone the user reports to, nearest first (their manager, that manager's manager, ...).
     *
     * @return array<int, string>
     */
    public function managersOf(string $tenantId, string $userId): array
    {
        $tree = $this->tree($tenantId);
        $managers = [];
        $current = $tree[$userId] ?? null;

        while ($current !== null && ! in_array($current, $managers, true) && $current !== $userId) {
            $managers[] = $current;
            $current = $tree[$current] ?? null;
        }

        return $managers;
    }

    /**
     * Whether making `$managerId` the manager of `$user` would close a loop (a person cannot report to themselves or their own team).
     */
    public function wouldCreateLoop(User $user, string $managerId): bool
    {
        return $managerId === $user->id || in_array($managerId, $this->teamIds($user), true);
    }

    public function forget(): void
    {
        $this->trees = [];
    }

    /**
     * @return array<string, ?string>
     */
    private function tree(string $tenantId): array
    {
        return $this->trees[$tenantId] ??= DB::table('users')->where('tenant_id', $tenantId)->pluck('manager_id', 'id')->all();
    }
}

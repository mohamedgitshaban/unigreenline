<?php

namespace Modules\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;

/**
 * Which warehouses a user may work with (spec §2, layer 1): the ones they are assigned to
 * (`user_warehouses`), the ones they manage (`warehouses.manager_id`), and — so a manager sees everything
 * their people see — those of everyone who reports to them. Administrator, Owner and Auditor see every
 * warehouse in the company.
 */
class WarehouseAccess
{
    public function __construct(private readonly DataScope $scope) {}

    /**
     * The visible warehouse ids, or null when the user sees every warehouse of the company.
     *
     * @return array<int, string>|null
     */
    public function idsFor(User $user): ?array
    {
        if ($user->hasAnyRole(['Administrator', 'Owner', 'Auditor'])) {
            return null;
        }

        $people = [$user->id, ...$this->scope->teamIds($user)];

        $assigned = DB::table('user_warehouses')->whereIn('user_id', $people)->pluck('warehouse_id');
        $managed = DB::table('warehouses')->where('tenant_id', $user->tenant_id)->whereIn('manager_id', $people)->pluck('id');

        return $assigned->merge($managed)->unique()->values()->all();
    }

    public function canSee(User $user, string $warehouseId): bool
    {
        $ids = $this->idsFor($user);

        return $ids === null || in_array($warehouseId, $ids, true);
    }

    /**
     * The users who answer for a warehouse: its manager. (People merely assigned to it work there but do not run it.)
     *
     * @return array<int, string>
     */
    public function managersOf(string $warehouseId): array
    {
        return array_values(array_filter([DB::table('warehouses')->where('id', $warehouseId)->value('manager_id')]));
    }
}

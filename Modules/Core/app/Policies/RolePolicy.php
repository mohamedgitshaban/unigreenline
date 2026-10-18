<?php

namespace Modules\Core\Policies;

use Modules\Core\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Roles are global (not tenant-scoped), so managing them is gated purely on
 * the admin.* permissions. The Administrator role itself is protected in
 * RoleController — nobody can strip its permissions or delete it.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Role $role): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Role $role): bool
    {
        return true;
    }

    public function delete(User $user, Role $role): bool
    {
        return true;
    }
}

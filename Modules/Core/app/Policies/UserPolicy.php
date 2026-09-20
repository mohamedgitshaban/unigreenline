<?php

namespace Modules\Core\Policies;

use Modules\Core\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.audit');
    }

    public function view(User $user, User $target): bool
    {
        return $user->can('admin.audit');
    }

    public function create(User $user): bool
    {
        return $user->can('admin.add');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('admin.edit');
    }
}

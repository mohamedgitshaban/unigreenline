<?php

namespace Modules\Core\Policies;

use Modules\Core\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $target): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, User $target): bool
    {
        return true;
    }

    public function delete(User $user, User $target): bool
    {
        return true;
    }
}

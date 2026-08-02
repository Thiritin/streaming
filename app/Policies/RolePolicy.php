<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Roles carry the permission strings the whole panel is gated on, so editing them
 * needs `user.manage`. Deleting is additionally blocked while the role still has
 * members: dropping it would silently strip whatever it granted them.
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
        return $this->manages($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->manages($user) && ! $role->users()->exists();
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('user.manage') || $user->hasPermission('admin.access');
    }
}

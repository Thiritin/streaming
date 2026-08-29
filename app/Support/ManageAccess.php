<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may reach /manage, defined once.
 *
 * The `access-manage` gate and the sign-in safeguard both have to answer this, one
 * about a person in front of it and one about whether anybody at all is left. Asking
 * it in two places is how the safeguard came to count only `admin.access` while the
 * gate also let `filament.access` through - and `filament.access` is what the role
 * rows in production actually carry, so the safeguard saw no administrators on the
 * installations that have the most.
 *
 * One predicate over a role answers both.
 */
final class ManageAccess
{
    /**
     * Permissions that open the panel. `filament.access` is kept because it is the
     * string stored on existing role rows; renaming it would need a data migration.
     *
     * @var array<int, string>
     */
    public const PERMISSIONS = ['admin.access', 'filament.access'];

    /**
     * The role that opens the panel by being itself, whatever it carries.
     */
    public const STAFF_ROLE = 'admin';

    /**
     * Whether one role opens the panel. Every other answer here is built on this.
     */
    public static function granted(Role $role): bool
    {
        if ($role->slug === self::STAFF_ROLE) {
            return true;
        }

        foreach (self::PERMISSIONS as $permission) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this account may reach the panel. What the gate is defined as.
     */
    public static function allows(User $user): bool
    {
        return $user->activeRoles->contains(fn (Role $role) => self::granted($role));
    }

    /**
     * The ids of every role that opens the panel, for asking the question of a whole
     * table at once. Filtered in PHP because `permissions` is a JSON column and the
     * roles table is small enough that reading it is cheaper than a portable query
     * over one.
     *
     * @return Collection<int, int>
     */
    public static function roleIds(): Collection
    {
        return Role::query()
            ->get(['id', 'slug', 'permissions'])
            ->filter(fn (Role $role) => self::granted($role))
            ->pluck('id');
    }
}

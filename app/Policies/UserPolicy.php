<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may look at and edit attendee records.
 *
 * Same shape as the other /manage policies: reading is open to anyone past the
 * `access-manage` gate, mutating needs `user.manage`. Creating an account and
 * setting a password on one are held to `admin.access` instead, the same bar as
 * the pane that switches the sign-in modes: both hand out a way in.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $subject): bool
    {
        return true;
    }

    /**
     * An account this installation holds itself, rather than one the identity
     * provider owns.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('admin.access');
    }

    /**
     * Confirming an address by hand, for an installation that cannot send mail.
     * Held at the same bar as setting a password: both decide who is a full account.
     */
    public function verifyEmail(User $user, User $subject): bool
    {
        return $user->hasPermission('admin.access');
    }

    public function update(User $user, User $subject): bool
    {
        return $this->manages($user);
    }

    /**
     * Setting or clearing a password on an existing account.
     */
    public function managePassword(User $user, User $subject): bool
    {
        return $user->hasPermission('admin.access');
    }

    /**
     * Deleting yourself would end the session that is doing the deleting.
     */
    public function delete(User $user, User $subject): bool
    {
        return $this->manages($user) && $user->id !== $subject->id;
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('user.manage') || $user->hasPermission('admin.access');
    }
}

<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may look at and edit attendee records.
 *
 * Same shape as the other /manage policies: reading is open to anyone past the
 * `access-manage` gate, mutating needs `user.manage`. There is no create: users
 * arrive through OIDC, and every identity field on the form is read-only because
 * the identity provider owns it.
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

    public function update(User $user, User $subject): bool
    {
        return $this->manages($user);
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

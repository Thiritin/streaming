<?php

namespace App\Policies;

use App\Models\DisplayScreen;
use App\Models\User;

/**
 * Same shape as EmbedKeyPolicy: reading is open to anyone past the `access-manage`
 * gate, moving a screen or forgetting one needs `stream.manage`.
 */
class DisplayScreenPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DisplayScreen $screen): bool
    {
        return true;
    }

    /**
     * Nothing creates a screen from /manage - the screen creates itself by presenting
     * a key. This is the gate on the batch actions, which act on screens as a set.
     */
    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, DisplayScreen $screen): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, DisplayScreen $screen): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('stream.manage') || $user->hasPermission('admin.access') || $user->isStaff();
    }
}

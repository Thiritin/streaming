<?php

namespace App\Policies;

use App\Models\Show;
use App\Models\User;

class ShowPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Show $show): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Show $show): bool
    {
        return $this->manages($user);
    }

    /**
     * A live show cannot be deleted; it has to be ended first. Filament enforced this with
     * a danger toast from the delete action, which meant the rule lived in the UI.
     */
    public function delete(User $user, Show $show): bool
    {
        return $this->manages($user) && $show->status !== 'live';
    }

    public function goLive(User $user, Show $show): bool
    {
        return $this->manages($user) && $show->status === 'scheduled';
    }

    public function endStream(User $user, Show $show): bool
    {
        return $this->manages($user) && $show->status === 'live';
    }

    public function cancel(User $user, Show $show): bool
    {
        return $this->manages($user) && $show->status === 'scheduled';
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('stream.manage') || $user->hasPermission('admin.access') || $user->isStaff();
    }
}

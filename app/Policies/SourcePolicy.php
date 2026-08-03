<?php

namespace App\Policies;

use App\Models\Source;
use App\Models\User;

/**
 * Who may do what to stream sources.
 *
 * Same shape as ServerPolicy: reading is open to anyone past the `access-manage` gate,
 * mutating needs `stream.manage`. Deleting is additionally blocked while the source has a
 * live show, which is the guard the Filament panel enforced with a danger toast.
 */
class SourcePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Source $source): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Source $source): bool
    {
        return $this->manages($user);
    }

    /**
     * A source with a live show on it cannot be deleted: the stream would keep running
     * with nothing describing it.
     */
    public function delete(User $user, Source $source): bool
    {
        return $this->manages($user) && ! $source->liveShows()->exists();
    }

    /**
     * Rotating the stream key drops whoever is currently pushing to it.
     */
    public function regenerateStreamKey(User $user, Source $source): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('stream.manage') || $user->hasPermission('admin.access') || $user->isStaff();
    }
}

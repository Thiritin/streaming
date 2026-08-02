<?php

namespace App\Policies;

use App\Models\Recording;
use App\Models\User;

/**
 * Recordings are published content, so they follow `stream.manage` like the shows
 * they come from.
 */
class RecordingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Recording $recording): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Recording $recording): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Recording $recording): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('stream.manage') || $user->hasPermission('admin.access');
    }
}

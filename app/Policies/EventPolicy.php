<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

/**
 * Same shape as CategoryPolicy: the calendar is visible to anyone in the panel,
 * and setting it is a streaming-management job. It gates no viewer access, so
 * there is nothing here that a role could open by accident.
 */
class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Event $event): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('stream.manage') || $user->hasPermission('admin.access');
    }
}

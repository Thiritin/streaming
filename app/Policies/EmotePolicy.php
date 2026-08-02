<?php

namespace App\Policies;

use App\Models\Emote;
use App\Models\User;

/**
 * Emotes are chat furniture, so moderating them follows the chat permissions
 * rather than the streaming ones.
 */
class EmotePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Emote $emote): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->moderates($user);
    }

    public function update(User $user, Emote $emote): bool
    {
        return $this->moderates($user);
    }

    public function delete(User $user, Emote $emote): bool
    {
        return $this->moderates($user);
    }

    /**
     * Approving is what puts an emote in front of every viewer, so it is the same
     * bar as editing one.
     */
    public function approve(User $user, Emote $emote): bool
    {
        return $this->moderates($user);
    }

    private function moderates(User $user): bool
    {
        return $user->hasPermission('chat.moderate') || $user->hasPermission('admin.access');
    }
}

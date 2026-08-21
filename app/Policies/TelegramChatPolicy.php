<?php

namespace App\Policies;

use App\Models\TelegramChat;
use App\Models\User;

/**
 * Stricter than the other modules on purpose. An interactive chat can start and end
 * shows, so deciding which chats exist and what they may press is the same kind of
 * decision as handing out a control key: administrators only.
 */
class TelegramChatPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->administers($user);
    }

    public function view(User $user, TelegramChat $chat): bool
    {
        return $this->administers($user);
    }

    public function create(User $user): bool
    {
        return $this->administers($user);
    }

    public function update(User $user, TelegramChat $chat): bool
    {
        return $this->administers($user);
    }

    public function delete(User $user, TelegramChat $chat): bool
    {
        return $this->administers($user);
    }

    private function administers(User $user): bool
    {
        return $user->hasPermission('admin.access');
    }
}

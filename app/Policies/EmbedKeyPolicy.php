<?php

namespace App\Policies;

use App\Models\EmbedKey;
use App\Models\User;

/**
 * Same shape as SourcePolicy: reading is open to anyone past the `access-manage`
 * gate, minting and revoking needs `stream.manage`.
 */
class EmbedKeyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EmbedKey $key): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, EmbedKey $key): bool
    {
        return $this->manages($user);
    }

    /**
     * Deleting the row is the revocation mechanism, so this is the one that matters:
     * a screen loses access on the next page load.
     */
    public function delete(User $user, EmbedKey $key): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('stream.manage') || $user->hasPermission('admin.access') || $user->isStaff();
    }
}

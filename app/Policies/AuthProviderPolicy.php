<?php

namespace App\Policies;

use App\Models\AuthProvider;
use App\Models\User;

/**
 * A provider row is a credential and a way in, so it is held to admin.access - the
 * same bar as the pane that switches the sign-in modes - rather than to access-manage.
 */
class AuthProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.access');
    }

    public function view(User $user, AuthProvider $provider): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, AuthProvider $provider): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, AuthProvider $provider): bool
    {
        return $this->viewAny($user);
    }
}

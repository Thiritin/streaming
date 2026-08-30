<?php

namespace App\Observers;

use App\Models\User;
use App\Support\BanCarryOver;

class UserObserver
{
    /**
     * A sanction parked when this identity deleted its last account goes back on.
     * Here rather than in the OIDC controller so any path that remakes an account
     * picks it up.
     */
    public function created(User $user): void
    {
        BanCarryOver::claim($user);
    }

    public function updated(User $user): void {}

    public function deleted(User $user): void {}

    public function restored(User $user): void {}

    public function forceDeleted(User $user): void {}
}

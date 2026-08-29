<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Verified;

/**
 * The baseline role is what makes an account an attendee, so an account that
 * registered itself only gets it once the address has been confirmed. An account the
 * identity provider owns never comes through here: the provider vouches for it and
 * the callback's own mapping hands it the same role.
 */
class AssignBaselineRole
{
    public function handle(Verified $event): void
    {
        $event->user->assignBaselineRole();
    }
}

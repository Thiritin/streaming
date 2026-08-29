<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Manage\Toast;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;

/**
 * Confirming an address on somebody else's behalf.
 *
 * Self-registration proves an address by writing to it, which an installation with no
 * mail configured cannot do - and an account that never confirms never gets the
 * baseline role. This is the way through that: the same decision, made by an
 * administrator instead of by a mail client, so it sits at the same bar as setting a
 * password on an account.
 */
class UserVerificationController extends Controller
{
    public function store(User $user): RedirectResponse
    {
        $this->authorize('verifyEmail', $user);

        if ($user->hasVerifiedEmail()) {
            return back();
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        Toast::flashSuccess('Address confirmed', "'{$user->name}' is a full account.");

        return back();
    }
}

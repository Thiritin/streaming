<?php

namespace App\Http\Controllers;

use App\Models\AuthProvider;
use App\Support\Auth\ProviderFlow;
use App\Support\Auth\ProviderRoles;
use App\Support\AuthModes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A viewer's own ways in: connecting another provider to the account they already
 * hold, and taking one off again.
 *
 * Connect is not a second OAuth path. It puts the intent in the session and hands over
 * to the same redirect controller the sign-in button uses, so there is one flow, one
 * callback, and one place the collision rule lives.
 */
class ConnectionsController extends Controller
{
    public function connect(Request $request, AuthProvider $provider): RedirectResponse
    {
        // A row that is switched off, or shut out by the master switch, is nothing to
        // connect to - the redirect route answers 404 for it and so does this.
        abort_unless($provider->isUsable() && AuthModes::oauth2(), 404);

        $request->session()->put('auth.intent', ProviderFlow::CONNECT);

        return redirect()->route('auth.provider.redirect', $provider->key);
    }

    /**
     * Bound by id rather than by key, because a provider an administrator has switched
     * off still has to be disconnectable.
     */
    public function destroy(Request $request, AuthProvider $provider): RedirectResponse
    {
        $user = $request->user();

        $identity = $user->identities()->where('auth_provider_id', $provider->id)->first();

        if ($identity === null) {
            return back()->withErrors(['connection' => "This account is not connected to {$provider->label}."]);
        }

        if ($user->signInMethodCount() <= 1) {
            return back()->withErrors(['connection' => 'That is the only way into this account.']);
        }

        // Read before the row goes: what this provider could grant is what it may take
        // away, and there is nothing left to ask once the identity is deleted.
        $releasing = ProviderRoles::grantable($provider);

        $identity->delete();

        ProviderRoles::sync($user->fresh(), $releasing);

        return back()->with('status', "Disconnected from {$provider->label}.");
    }
}

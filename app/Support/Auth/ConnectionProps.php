<?php

namespace App\Support\Auth;

use App\Models\AuthProvider;
use App\Models\User;
use App\Support\AuthModes;

/**
 * What /settings/connections renders: one row per provider, plus whether this account
 * could afford to lose each of them.
 *
 * A provider an administrator has since switched off still appears while the account
 * holds an identity on it, or there would be no way to disconnect it.
 */
final class ConnectionProps
{
    /**
     * @return array{providers: array<int, array<string, mixed>>, hasPassword: bool, canSetPassword: bool, methodCount: int}
     */
    public static function for(User $user): array
    {
        $identities = $user->identities()->get()->keyBy('auth_provider_id');

        $providers = AuthProvider::query()
            ->ordered()
            ->get()
            ->filter(fn (AuthProvider $provider) => $provider->isUsable() || $identities->has($provider->id))
            ->values();

        $methods = $user->signInMethodCount();

        return [
            'providers' => $providers->map(function (AuthProvider $provider) use ($identities, $methods) {
                $identity = $identities->get($provider->id);

                return [
                    'id' => $provider->id,
                    'key' => $provider->key,
                    'label' => $provider->label,
                    'connected' => $identity !== null,
                    'connectedAt' => $identity?->created_at?->toIso8601String(),
                    'connectUrl' => self::offered($provider) ? route('settings.connections.connect', $provider->key) : null,
                    'disconnectUrl' => $identity === null ? null : route('settings.connections.destroy', $provider->id),
                    // Never the last way in. Somebody who disconnects it has no way to
                    // ask for it back: the account is still there and nothing opens it.
                    'canDisconnect' => $identity !== null && $methods > 1,
                ];
            })->all(),
            'hasPassword' => $user->password !== null,
            'canSetPassword' => (bool) config('auth.modes.local'),
            'methodCount' => $methods,
        ];
    }

    /**
     * Whether this row can be connected right now: set up, switched on, and not shut
     * out by the master switch over all of them. Only the button hangs off this - the
     * row is still listed, so an operator pausing new connections does not make the
     * ones people already hold invisible.
     */
    private static function offered(AuthProvider $provider): bool
    {
        return $provider->isUsable() && AuthModes::oauth2();
    }

    /**
     * Whether the page is worth offering at all: something to connect, or something
     * already connected to look at.
     */
    public static function availableTo(User $user): bool
    {
        /*
         * Deliberately not gated on the master switch, only on there being rows worth
         * showing. Two reasons: switching every provider off must not delete the page
         * somebody uses to see and remove the connections they already hold, and the
         * connect flow lands here whatever it decides - so a page that can vanish is a
         * refused connect redirecting to a 404.
         */
        return AuthProvider::usable()->isNotEmpty() || $user->identities()->exists();
    }
}

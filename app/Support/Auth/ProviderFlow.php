<?php

namespace App\Support\Auth;

use App\Models\AuthProvider;
use App\Services\Auth\ProviderFactory;
use Illuminate\Http\Request;

/**
 * One round trip to a provider, and what it is for.
 *
 * Three intents share one redirect and one callback: signing in, connecting another
 * provider to an account that is already signed in, and an administrator testing a
 * provider before anybody is let near it.
 *
 * The intent is never in a URL. It is written into the session by the route that
 * starts the flow - each of which is behind its own gate, `admin.access` for a test
 * and `auth:web` for a connect - and read once on the way back. So a crafted callback
 * URL, which carries only `code` and `state`, cannot turn a sign-in into a test or a
 * test into a sign-in: it has no way to write the record that decides.
 *
 * The record also carries the provider and the CSRF state Socialite issued for this
 * particular round trip, and is honoured only when both match what came back. That is
 * what stops an abandoned test - pressed, never finished - from being picked up by an
 * unrelated sign-in in the same session an hour later.
 */
final class ProviderFlow
{
    public const SIGN_IN = 'signin';

    public const CONNECT = 'connect';

    public const TEST = 'test';

    private const KEY = 'auth.flow';

    /**
     * Build the driver, leave for the provider, and record what the trip is for.
     */
    public static function start(Request $request, AuthProvider $provider, string $intent): mixed
    {
        $response = app(ProviderFactory::class)->make($provider)->redirect();

        $request->session()->put(self::KEY, [
            'intent' => $intent,
            // Socialite writes the state as it builds the authorize URL, so it is read
            // back rather than generated here: one value, issued in one place.
            'state' => $request->session()->get('state'),
            'provider' => $provider->id,
        ]);

        return $response;
    }

    /**
     * What the trip that is coming back was for. Consumed either way, so a record that
     * does not match this callback cannot survive to confuse the next one.
     */
    public static function intent(Request $request, AuthProvider $provider): string
    {
        $flow = $request->session()->pull(self::KEY);

        if (! is_array($flow) || ($flow['provider'] ?? null) !== $provider->id) {
            return self::SIGN_IN;
        }

        $returned = $request->query('state');

        /*
         * A provider that refuses an authorize request does not always echo the state
         * back, and that failure still belongs to whoever pressed the button. So the
         * state has to match when there is one, and the provider alone decides when
         * there is not - which is safe, because a record only exists at all if this
         * session started the flow.
         */
        if (filled($returned) && $returned !== ($flow['state'] ?? null)) {
            return self::SIGN_IN;
        }

        return in_array($flow['intent'] ?? null, [self::CONNECT, self::TEST], true)
            ? $flow['intent']
            : self::SIGN_IN;
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }
}

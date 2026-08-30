<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthProvider;
use App\Providers\RouteServiceProvider;
use App\Support\Auth\ProviderFlow;
use App\Support\Auth\RedirectUri;
use App\Support\AuthModes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Sending somebody to a provider, whichever one they pressed.
 *
 * One controller for signing in and for connecting another way in to an account that
 * is already signed in; the intent is in the session before this runs and all this
 * does is leave. An administrator's test starts from /manage instead, because it has
 * to work for a provider that is switched off, which this route answers 404 for.
 */
class ProviderRedirectController extends Controller
{
    public function __invoke(Request $request, AuthProvider $provider): RedirectResponse|SymfonyRedirect
    {
        // A way in that is switched off, here or by the master switch over all of them,
        // is not a route - the same way every switched-off route in this app closes.
        abort_unless($provider->isUsable() && AuthModes::oauth2(), 404);

        return $this->start($request, $provider);
    }

    /**
     * `/auth/login`, kept because it is in bookmarks, in the layout and on the sign-in
     * screen.
     */
    public function legacy(Request $request): RedirectResponse|SymfonyRedirect
    {
        $legacy = AuthProvider::legacy();

        // An installation that never had the convention's own provider, or has since
        // switched it off, still means "the provider way in" by this URL. With one row
        // there is nothing to pick between; once the screen draws a button per row,
        // nothing arrives here at all.
        $provider = $legacy !== null && $legacy->isUsable() ? $legacy : AuthModes::providers()->first();

        abort_if($provider === null, 404);

        return $this->start($request, $provider);
    }

    private function start(Request $request, AuthProvider $provider): RedirectResponse|SymfonyRedirect
    {
        $connecting = $request->session()->get('auth.intent') === ProviderFlow::CONNECT;

        // Already signed in and not here to connect anything: there is nothing to do,
        // and starting a flow would sign the same person in again.
        if ($request->user() && ! $connecting) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        $request->session()->forget('auth.intent');

        if ($rejection = RedirectUri::rejection($provider->redirectUrl())) {
            Log::error('Provider redirect URI will be rejected', [
                'provider' => $provider->key,
                'redirect_uri' => $provider->redirectUrl(),
                'reason' => $rejection,
            ]);

            if (! app()->isProduction()) {
                return redirect()->route('login')->withErrors(['oidc' => $rejection]);
            }
        }

        return ProviderFlow::start(
            $request,
            $provider,
            $connecting ? ProviderFlow::CONNECT : ProviderFlow::SIGN_IN,
        );
    }
}

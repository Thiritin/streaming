<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthProvider;
use App\Providers\RouteServiceProvider;
use App\Services\Auth\ProviderFactory;
use App\Support\Auth\IdentityLinker;
use App\Support\Auth\ProviderFlow;
use App\Support\Auth\ProviderRoles;
use App\Support\Auth\ProviderTestReport;
use App\Support\AuthModes;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as RemoteUser;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Coming back from a provider. One controller for all of them, and for both intents.
 *
 * The session is never flushed here. Flushing takes the CSRF token, the intended URL
 * and - fatally for the connect flow - the signed-in user, so connecting would quietly
 * become "create a second account". Laravel's own login migrates the session anyway.
 */
class ProviderCallbackController extends Controller
{
    public function __invoke(Request $request, AuthProvider $provider): RedirectResponse
    {
        return $this->handle($request, $provider);
    }

    /**
     * `/auth/callback`, the URI the convention's provider already has registered. The
     * row is resolved by the path it claims rather than from the URL, which carries no
     * key; the unique index on `redirect_path` is what keeps that answer single.
     */
    public function legacy(Request $request): RedirectResponse
    {
        $provider = AuthProvider::legacy();

        abort_if($provider === null, 404);

        return $this->handle($request, $provider);
    }

    private function handle(Request $request, AuthProvider $provider): RedirectResponse
    {
        $intent = ProviderFlow::intent($request, $provider);

        // A test is an administrator checking a provider before anybody is let near it,
        // so it is held to admin.access on the way back as well as on the way out: the
        // session that started it may not be the session that returns.
        if ($intent === ProviderFlow::TEST && ! $request->user()?->hasPermission('admin.access')) {
            $intent = ProviderFlow::SIGN_IN;
        }

        $testing = $intent === ProviderFlow::TEST;
        $connecting = $intent === ProviderFlow::CONNECT;

        if ($request->filled('error')) {
            Log::warning('Provider callback returned an error', [
                'provider' => $provider->key,
                'error' => $request->query('error'),
                'description' => $request->query('error_description'),
            ]);

            /*
             * The provider rejected the authorize request for a reason that has nothing
             * to do with this app - a replayed flow, a rotated CSRF cookie on its side,
             * an expired flow. Bouncing back to the redirect route would start another
             * authorize round and loop, hiding the error, so stop at the sign-in screen.
             * Its own description is kept out of the response: it leaks internals and
             * means nothing to an attendee. It is in the log for whoever is debugging.
             */
            return $testing
                ? $this->reported($provider, ProviderTestReport::failure($provider, $this->refusal($request, $provider)))
                : $this->failed($connecting, 'Sign-in was refused. Start again from this page, in a single tab.');
        }

        // A switched-off row is exactly what a test exists to check, so the usability
        // gate is the one thing a test steps past. Everything after this point is the
        // same code a real sign-in runs.
        if (! $testing && (! $provider->isUsable() || ! AuthModes::oauth2())) {
            return $this->failed($connecting, 'That way in is not available.');
        }

        try {
            $remote = app(ProviderFactory::class)->make($provider)->user();
        } catch (InvalidStateException) {
            return $testing
                ? $this->reported($provider, ProviderTestReport::failure(
                    $provider,
                    'The test expired, or it was started in another tab. Run it again from this page.',
                ))
                : $this->failed($connecting, 'The sign-in request expired or was started in another session.');
        } catch (Throwable $e) {
            Log::warning('Provider token exchange failed', [
                'provider' => $provider->key,
                'error' => $e->getMessage(),
            ]);

            return $testing
                ? $this->reported($provider, ProviderTestReport::failure($provider, $this->exchangeFailure($e)))
                : $this->failed($connecting, "Your account details could not be read from {$provider->label}.");
        }

        $claims = $this->claims($provider, $remote);

        /*
         * A test stops here. Nothing is written - no account, no identity, no role -
         * and the session is left exactly as it was, so the administrator who pressed
         * the button is still signed in as themselves.
         */
        if ($testing) {
            return $this->reported($provider, ProviderTestReport::of($provider, $remote, $claims));
        }

        $result = app(IdentityLinker::class)->resolve($provider, $remote, $connecting ? $request->user() : null);

        if ($result->failed()) {
            return $this->failed($connecting, $result->error);
        }

        ProviderRoles::apply($result->identity, $provider, $claims);

        if ($connecting) {
            return redirect()
                ->route('settings.edit', 'connections')
                ->with('status', "Connected to {$provider->label}.");
        }

        // Remembered, so the sign-in survives the session cookie expiring or the
        // session store being cleared. Attendees should not be bounced back to the
        // provider mid-convention.
        Auth::loginUsingId($result->user->id, remember: true);

        // Which row this session came in through, so signing out knows whether there
        // is a front channel to leave by.
        $request->session()->put('auth.signed_in_with', $provider->id);

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * A finished test goes back to the provider's own page carrying its result, which
     * is shown once and stored nowhere.
     *
     * @param  array<string, mixed>  $report
     */
    private function reported(AuthProvider $provider, array $report): RedirectResponse
    {
        return redirect()
            ->route('manage.providers.edit', $provider)
            ->with('provider.test', $report);
    }

    /**
     * What the provider said when it refused, in the two forms an operator can act on.
     *
     * `redirect_uri_mismatch` is the one worth naming: it is the single most common
     * setup mistake and it is invisible until a full round trip has failed, so the
     * answer carries the URL that has to be registered rather than only the fact.
     */
    private function refusal(Request $request, AuthProvider $provider): string
    {
        $code = (string) $request->query('error');

        if (str_contains($code, 'redirect_uri') || str_contains($code, 'invalid_request')) {
            return 'The provider refused the callback URL. Register exactly this one against this client: '
                .$provider->redirectUrl();
        }

        if (str_contains($code, 'client')) {
            return 'The provider did not recognise the client ID.';
        }

        if (str_contains($code, 'scope')) {
            return 'The provider refused one of the scopes this row asks for.';
        }

        return 'The provider refused the request: '.($code ?: 'no reason given').'.';
    }

    /**
     * The same for a failure on our side of the exchange, where there is no error code
     * to read and only the status tells the two apart.
     */
    private function exchangeFailure(Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, '401') || str_contains($message, 'invalid_client')) {
            return 'The provider rejected the client secret.';
        }

        if (str_contains($message, 'released no')) {
            // OidcProvider says which endpoint the discovery document is missing.
            return 'The provider published no discovery document, or it is missing an endpoint. '
                .'Set the endpoints on this page instead.';
        }

        if (str_contains($message, 'cURL') || str_contains($message, 'Connection')) {
            return 'The provider could not be reached from this server.';
        }

        return 'The exchange with the provider failed after it signed the person in.';
    }

    /**
     * Everything this sign-in released, keyed by claim name, for the role mapping.
     *
     * @return array<string, mixed>
     */
    private function claims(AuthProvider $provider, RemoteUser $remote): array
    {
        $claims = is_array($remote->user ?? null) ? $remote->user : [];

        if (filled($provider->packages_url)) {
            $claims['packages'] = $this->packages($provider, (string) $remote->getId());
        }

        return $claims;
    }

    /**
     * Registration packages, for a provider that keeps them somewhere else.
     *
     * Optional on purpose: a registration system that is offline must not stop anybody
     * signing in, so every failure answers an empty list.
     *
     * @return array<int, mixed>
     */
    private function packages(AuthProvider $provider, string $subject): array
    {
        try {
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->get(rtrim((string) $provider->packages_url, '/').'/api/v1/attendees/'.$subject);

            if (! $response->successful()) {
                return [];
            }

            return (array) ($response->json()['packages'] ?? []);
        } catch (ConnectionException|Throwable $e) {
            Log::info('Registration system unreachable, continuing without packages', [
                'provider' => $provider->key,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function failed(bool $connecting, string $reason): RedirectResponse
    {
        if ($connecting) {
            return redirect()->route('settings.edit', 'connections')->withErrors(['connection' => $reason]);
        }

        return redirect()->route('login')->withErrors(['oidc' => $reason]);
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\Hydra\Client;
use App\Services\OpenIDService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use UnexpectedValueException;

class OidcClientController extends Controller
{
    private OpenIDService $openIDService;

    public function __construct()
    {
        $this->openIDService = new OpenIDService;
    }

    public function callback(Request $request)
    {
        $data = $request->validate([
            'state' => 'required_with::code|string',
            'error' => 'nullable|required_without:code|string',
            'error_description' => 'nullable|required_without:code|string',
            'code' => 'nullable|string',
        ]);
        /**
         * Only Identity Client - Redirects to error page if scope is invalid
         */
        Log::info('OIDC DEBUG callback entered', ['query' => $request->query(), 'session_id' => Session::getId()]);
        if (isset($data['error'])) {
            Log::warning('OIDC callback returned an error from the identity provider', $data);

            // The provider rejected the authorize request (a replayed flow, a rotated CSRF
            // cookie on its side, an expired flow). Bouncing to auth.login would start yet
            // another authorize round and loop, hiding the error, so stop at the sign-in
            // screen and say so.
            return $this->failed('Sign-in was refused. Start again from this page, in a single tab.');
        }

        /**
         * State Verification
         * Do not delete the default "false" parameter of Session::get
         * otherwise null === null and it would pass the check falsely.
         */
        if ($request->get('state') !== Session::get('login.oauth2state', false)) {
            Log::warning('OIDC callback state did not match the session', [
                'got' => $request->get('state'),
                'expected' => Session::get('login.oauth2state', false),
            ]);
            Session::remove('login.oauth2state');

            return $this->failed('The sign-in request expired or was started in another session.');
        }
        Session::flush();
        /**
         * Get Tokens
         */
        $provider = $this->openIDService->setupOIDC($request, $this->clientIsAdmin($request));
        $accessToken = $provider->getAccessToken('authorization_code', [
            'code' => $data['code'],
        ]);
        $userinfoRequest = Http::identity()->withToken($accessToken->getToken())->get('/api/v1/userinfo');
        if ($userinfoRequest->successful() === false) {
            Log::warning('OIDC userinfo request failed', ['status' => $userinfoRequest->status(), 'body' => $userinfoRequest->body()]);

            // Named via BrandingService so a saved override wins over the config default.
            $identity = app(BrandingService::class)->all()['identity_name'] ?? 'the identity provider';

            return $this->failed("Your account details could not be read from {$identity}.");
        }
        $userinfo = $userinfoRequest->json();
        Log::info('OIDC DEBUG userinfo ok', ['keys' => array_keys($userinfo ?? [])]);

        if (! isset($userinfo['sub'])) {
            throw new UnexpectedValueException('Could not request user id from freshly fetched token.');
        }

        $userid = $userinfo['sub'];
        $user = User::updateOrCreate([
            'sub' => $userinfo['sub'],
        ], [
            'name' => $userinfo['name'],
        ]);
        $user = $user->fresh();

        // Fetch attendee packages from the registration API
        $packages = $this->fetchAttendeePackages($userid);

        // Sync roles from registration system (groups and packages)
        $roleSlugs = $this->mapGroupsAndPackagesToRoles($userinfo['groups'] ?? [], $packages);
        $user->syncRolesFromLogin($roleSlugs);

        // Remembered, so the sign-in survives the session cookie expiring or the session
        // store being cleared. Attendees should not be bounced back to the identity
        // provider mid-convention.
        Auth::loginUsingId($user->id, remember: true);
        Session::put('access_token', $accessToken);
        Session::put('avatar', $userinfo['avatar'] ?? null);
        Log::info('OIDC DEBUG logged in', [
            'user_id' => $user->id,
            'auth_check' => Auth::check(),
            'session_id' => Session::getId(),
        ]);

        // Middleware will handle server assignment and redirect if needed
        return $this->redirectDestination($request);
    }

    public function login(Request $request): RedirectResponse
    {
        if ($rejection = $this->redirectUriRejection()) {
            Log::error('OIDC redirect URI will be rejected by the provider', [
                'redirect_uri' => route('auth.callback'),
                'reason' => $rejection,
            ]);

            if (! app()->isProduction()) {
                return $this->failed($rejection);
            }
        }

        $provider = $this->openIDService->setupOIDC($request, $this->clientIsAdmin($request));
        $authorizationUrl = $provider->getAuthorizationUrl();
        Session::put('login.oauth2state', $provider->getState());

        return Redirect::to($authorizationUrl);
    }

    public function clientIsAdmin(Request $request)
    {
        return false;
    }

    /**
     * End a broken sign-in at the sign-in screen rather than at the flow initiator.
     *
     * `auth.login` immediately redirects to the provider's authorize endpoint, so
     * redirecting a failure there restarts the flow: the operator sees a redirect loop
     * instead of a message, and the underlying error stays invisible.
     *
     * The provider's own description is kept out of the response on purpose; it leaks
     * internals ("the CSRF value from the token does not match ...") and means nothing to
     * an attendee. It is in the log for whoever is debugging.
     */
    /**
     * OAuth2 providers refuse a plain-http redirect URI unless the host is localhost or a
     * `*.localhost` subdomain. Ory Hydra answers with
     * `invalid_request: Redirect URL is using an insecure protocol ...` only *after* a full
     * round trip through the authorize endpoint, which reads like a login failure rather
     * than a misconfigured APP_URL. Catch it before leaving the app.
     *
     * @return string|null The reason, or null when the redirect URI is acceptable.
     */
    private function redirectUriRejection(): ?string
    {
        $uri = route('auth.callback');
        $parts = parse_url($uri);

        if (($parts['scheme'] ?? 'http') === 'https') {
            return null;
        }

        $host = $parts['host'] ?? '';

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }

        return "Sign-in is misconfigured: the callback URL {$uri} uses http, which the identity "
            .'provider only accepts for localhost hosts. Set APP_URL to an https URL, or to a '
            .'*.localhost host, and make sure that callback URL is registered for this client.';
    }

    private function failed(?string $reason = null): RedirectResponse
    {
        Session::remove('login.oauth2state');

        return Redirect::route('login')->withErrors([
            'oidc' => $reason ?? 'Sign-in could not be completed. Please try again.',
        ]);
    }

    private function redirectDestination(Request $request)
    {
        return Redirect::route('shows.grid');
    }

    /**
     * Fetch attendee packages from the registration API
     * This is optional - if the registration system is offline, we silently continue without packages
     */
    private function fetchAttendeePackages(string $userId): array
    {
        $attsrvUrl = config('services.attsrv.url');
        if (! $attsrvUrl) {
            Log::debug('ATTSRV_URL not configured, skipping package fetch');

            return [];
        }

        try {
            // Fetch attendee data from registration API with short timeout
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->get($attsrvUrl.'/api/v1/attendees/'.$userId);

            if (! $response->successful()) {
                Log::info('Registration system returned non-success status', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $attendeeData = $response->json();

            // Extract packages from the response
            $packages = $attendeeData['packages'] ?? [];

            Log::debug('Fetched attendee packages', [
                'user_id' => $userId,
                'packages' => $packages,
            ]);

            return $packages;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Registration system is offline/unreachable - this is expected in some environments
            Log::info('Registration system unreachable, continuing without packages', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Request failed (timeout, etc)
            Log::info('Registration system request failed, continuing without packages', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (\Exception $e) {
            // Any other unexpected error - log but don't fail login
            Log::warning('Unexpected error fetching attendee packages', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return [];
        }
    }

    /**
     * Map registration system groups and packages to role slugs
     */
    private function mapGroupsAndPackagesToRoles(array $groups, array $packages): array
    {
        $roles = [];

        // Check packages for sponsor/supersponsor
        foreach ($packages as $package) {
            $packageName = strtolower($package);

            if (str_contains($packageName, 'supersponsor')) {
                $roles[] = 'supersponsor';
            } elseif (str_contains($packageName, 'sponsor')) {
                $roles[] = 'sponsor';
            }
        }

        // Map groups to roles. The group IDs in the userinfo "groups" array are
        // issued by the installation's own identity provider, so the mapping is
        // configuration rather than something this class can know.
        $groupMapping = config('services.oidc.group_role_map', []);

        foreach ($groups as $group) {
            if (isset($groupMapping[$group])) {
                $roles[] = $groupMapping[$group];
            }
        }

        // Add attendee role as base role if not already included
        if (! in_array('attendee', $roles)) {
            $roles[] = 'attendee';
        }

        // Remove duplicates
        $roles = array_unique($roles);

        Log::info('Mapped roles for user', [
            'groups' => $groups,
            'packages' => $packages,
            'roles' => $roles,
        ]);

        return $roles;
    }
}

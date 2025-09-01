<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        if (isset($data['error'])) {
            return Redirect::route('auth.login');
        }

        /**
         * State Verification
         * Do not delete the default "false" parameter of Session::get
         * otherwise null === null and it would pass the check falsely.
         */
        if ($request->get('state') !== Session::get('login.oauth2state', false)) {
            Session::remove('login.oauth2state');

            return Redirect::route('auth.login');
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
            return Redirect::route('auth.login');
        }
        $userinfo = $userinfoRequest->json();

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

        // Fetch attendee packages from EF registration API
        $packages = $this->fetchAttendeePackages($userid);

        // Sync roles from registration system (groups and packages)
        $roleSlugs = $this->mapGroupsAndPackagesToRoles($userinfo['groups'] ?? [], $packages);
        $user->syncRolesFromLogin($roleSlugs);

        Auth::loginUsingId($user->id);
        Session::put('access_token', $accessToken);
        Session::put('avatar', $userinfo['avatar']);

        // Middleware will handle server assignment and redirect if needed
        return $this->redirectDestination($request);
    }

    public function login(Request $request): RedirectResponse
    {
        $provider = $this->openIDService->setupOIDC($request, $this->clientIsAdmin($request));
        $authorizationUrl = $provider->getAuthorizationUrl();
        Session::put('login.oauth2state', $provider->getState());

        return Redirect::to($authorizationUrl);
    }

    public function clientIsAdmin(Request $request)
    {
        return false;
    }

    private function redirectDestination(Request $request)
    {
        return Redirect::route('shows.grid');
    }

    /**
     * Fetch attendee packages from EF registration API
     */
    private function fetchAttendeePackages(string $userId): array
    {
        try {
            $attsrvUrl = config('services.attsrv.url');
            if (! $attsrvUrl) {
                Log::warning('ATTSRV_URL not configured, skipping package fetch');

                return [];
            }

            // Fetch attendee data from registration API
            $response = Http::timeout(5)->get($attsrvUrl.'/api/v1/attendees/'.$userId);

            if (! $response->successful()) {
                Log::warning('Failed to fetch attendee packages', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $attendeeData = $response->json();

            // Extract packages from the response
            // The exact structure depends on the EF API response format
            $packages = $attendeeData['packages'] ?? [];

            Log::info('Fetched attendee packages', [
                'user_id' => $userId,
                'packages' => $packages,
            ]);

            return $packages;
        } catch (\Exception $e) {
            Log::error('Error fetching attendee packages', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
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

        // Map groups to roles (for other roles like staff, moderator, etc.)
        $groupMapping = [
            'STAFF_GROUP' => 'staff',
            'MODERATOR_GROUP' => 'moderator',
            // Add more group mappings as needed
        ];

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

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

        // Sync roles from registration system
        $roleSlugs = $this->mapGroupsToRoles($userinfo['groups'] ?? []);
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
     * Map registration system groups to role slugs
     */
    private function mapGroupsToRoles(array $groups): array
    {
        $roleMapping = [
            // Map group IDs to role slugs
            // Example mappings - adjust based on your registration system
            'SUPER_SPONSOR_GROUP' => 'supersponsor',
            'SPONSOR_GROUP' => 'sponsor',
            'ATTENDEE_GROUP' => 'attendee',
            'STAFF_GROUP' => 'staff',
            // Add more mappings as needed
        ];

        $roles = [];
        foreach ($groups as $group) {
            if (isset($roleMapping[$group])) {
                $roles[] = $roleMapping[$group];
            }
        }

        // Default role if no specific roles found
        if (empty($roles)) {
            $roles[] = 'attendee';
        }

        return $roles;
    }
}

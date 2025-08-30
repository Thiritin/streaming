<?php

namespace App\Http\Middleware;

use App\Services\OpenIDService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Redirect;
use UnexpectedValueException;

class AccessTokenValidationMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        /**
         * Skip for tests, as Hydra is not available during tests.
         */
        if (config('app.env') === 'testing') {
            return $next($request);
        }

        $token = Session::get('access_token');

        /**
         * Logout if token does not exist
         */
        if ($token === null) {
            Auth::logout();

            return Redirect::route('auth.login');
        }

        /**
         * If token expired, refresh using refresh token.
         */
        if ($token->hasExpired()) {
            $provider = (new OpenIDService)->setupOIDC($request, false);
            try {
                $token = $provider->getAccessToken('refresh_token', [
                    'refresh_token' => $token->getRefreshToken(),
                ]);
            } catch (IdentityProviderException|UnexpectedValueException $exception) {
                // If refresh fails, try to reauth user.
                Auth::logout();

                return Redirect::route('auth.oidc.login');
            }

            Session::put('access_token', $token);

            return $next($request);
        }

        return $next($request);
    }
}

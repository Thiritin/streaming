<?php

namespace App\Http\Middleware;

use Closure;

/**
 * Authenticate only when `auth.required` says login is mandatory.
 *
 * Routes that carry this behave exactly like `auth` on an installation with
 * mandatory login. With `AUTH_REQUIRED=false` they let guests through and the
 * controllers behind them fall back to their nullable-user path, which the
 * access scopes already handle. Chat keeps the plain `auth` middleware, so it
 * stays sign-in only in both modes.
 */
class AuthenticateIfRequired extends Authenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        if (! config('auth.required')) {
            // Still resolve a session user when there is one, so pages can
            // greet them and restricted content stays visible to them.
            return $next($request);
        }

        return parent::handle($request, $next, ...$guards);
    }
}

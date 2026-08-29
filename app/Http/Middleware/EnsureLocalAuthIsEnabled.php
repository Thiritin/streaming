<?php

namespace App\Http\Middleware;

use App\Support\AuthModes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the password sign-in, registration and reset endpoints when this
 * installation does not hold accounts of its own.
 *
 * 404 rather than 403, the same way every feature switch here closes a route: a
 * mode that is off has no form, so there is nothing at the address.
 */
class EnsureLocalAuthIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(AuthModes::local(), 404);

        return $next($request);
    }
}

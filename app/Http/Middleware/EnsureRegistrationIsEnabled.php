<?php

namespace App\Http\Middleware;

use App\Support\AuthModes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes self-registration. Sits beside `auth.local` rather than replacing it, so
 * the two switches read the way they are written.
 */
class EnsureRegistrationIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(AuthModes::registration(), 404);

        return $next($request);
    }
}

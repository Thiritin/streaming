<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel does not exist for anyone who is not signed in.
 *
 * `auth:web` would bounce a guest to /login, which is the viewer sign-in screen and
 * says nothing about the panel; a stale bookmark then looks like a broken login rather
 * than a page that is simply not theirs. A signed-in account without the gate still
 * gets a 403 from `can:access-manage`.
 */
class HideManageFromGuests
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user(), 404);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the subscription endpoints when the installation has switched viewer
 * notifications off.
 *
 * The unsubscribe routes are deliberately not behind this. A message already sent has
 * a promise in its footer, and that promise has to keep working whatever the
 * installation switches off afterwards.
 */
class EnsureNotificationsAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::enabled('notifications'), 404, 'Notifications are disabled.');

        return $next($request);
    }
}

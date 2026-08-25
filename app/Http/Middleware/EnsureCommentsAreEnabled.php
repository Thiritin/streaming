<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the comment endpoints when the installation has switched comments off.
 *
 * The installation's switch and not the viewer's: somebody who hid comments for
 * themselves in /settings has no section to post from, and a viewer's own
 * preference is not an authorisation decision.
 */
class EnsureCommentsAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::comments(), 404, 'Comments are disabled.');

        return $next($request);
    }
}

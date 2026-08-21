<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the unattended-display routes when the installation has no screens.
 */
class EnsureScreensAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::screens(), 404, 'Screens are disabled.');

        return $next($request);
    }
}

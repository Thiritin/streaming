<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the feedback endpoints when the installation has switched reports off.
 */
class EnsureFeedbackIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::feedback(), 404, 'Feedback is disabled.');

        return $next($request);
    }
}

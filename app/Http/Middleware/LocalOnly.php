<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard gate for developer-only routes.
 *
 * The routes behind this are an authentication bypass, so this is deliberately
 * belt-and-braces: the route file is only registered when the app is local
 * (see RouteServiceProvider), and this rejects the request again at runtime in
 * case that ever changes or a route cache is copied between environments.
 */
class LocalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->isLocal(), 404);

        return $next($request);
    }
}

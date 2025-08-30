<?php

namespace App\Http\Middleware;

use App\Models\Server;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

class CheckSharedSecretMiddleware
{
    /**
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next)
    {
        $server = Server::where('shared_secret', $request->get('shared_secret'))->first();
        // Throw auth exception
        if (is_null($server)) {
            throw new AuthenticationException('Invalid shared secret');
        }

        return $next($request);
    }
}

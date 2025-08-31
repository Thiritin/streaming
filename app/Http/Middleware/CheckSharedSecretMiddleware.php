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
        // Check for shared secret in header first, then fall back to request parameter
        $sharedSecret = $request->header('X-Shared-Secret') ?: $request->get('shared_secret');
        
        $server = Server::where('shared_secret', $sharedSecret)->first();
        // Throw auth exception
        if (is_null($server)) {
            throw new AuthenticationException('Invalid shared secret');
        }

        return $next($request);
    }
}

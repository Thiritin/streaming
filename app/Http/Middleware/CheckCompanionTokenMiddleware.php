<?php

namespace App\Http\Middleware;

use App\Support\ControlKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a control surface against the installation's control key.
 *
 * One key for the whole installation: a surface says which source it drives in the URL,
 * and everyone who can reach one room can reach the others anyway. Rotating the key means
 * generating a new one at /manage > Settings and reconfiguring the surfaces.
 *
 * An unset key means the control API is off, which is the state of a fresh install.
 */
class CheckCompanionTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = ControlKey::current();
        $given = $request->header('X-Companion-Token') ?: $request->get('token');

        if (empty($expected) || ! is_string($given) || ! hash_equals($expected, $given)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or missing control key.',
            ], 401);
        }

        return $next($request);
    }
}

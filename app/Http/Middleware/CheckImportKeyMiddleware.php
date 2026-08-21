<?php

namespace App\Http\Middleware;

use App\Support\ImportKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the archive import API with the key from /manage > Settings > Imports.
 *
 * Separate from CheckRecordingApiKeyMiddleware on purpose: that one reads an env var set
 * once at deploy time, this one reads a row an operator can rotate without touching the
 * cluster. An installation that has never set a key does not have importing switched on,
 * so an empty setting refuses everything rather than accepting everything.
 */
class CheckImportKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = ImportKey::current();
        $provided = (string) ($request->header('X-Import-Key') ?: $request->get('import_key', ''));

        // hash_equals over ===, so a wrong key cannot be found a character at a time.
        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or missing import key. An operator sets one at /manage > Settings > Imports.',
            ], 401);
        }

        return $next($request);
    }
}

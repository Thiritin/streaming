<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRecordingApiKeyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check for API key in header first, then fall back to request parameter
        $apiKey = $request->header('X-Recording-Api-Key') ?: $request->get('api_key');

        // Set at /manage > Settings > Playback security, so it is read at call time
        // rather than off the environment.
        $expectedApiKey = config('app.recording_api_key');

        // Check if API key matches
        if (empty($expectedApiKey) || $apiKey !== $expectedApiKey) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or missing API key',
            ], 401);
        }

        return $next($request);
    }
}

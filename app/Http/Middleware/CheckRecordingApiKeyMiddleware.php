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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check for API key in header first, then fall back to request parameter
        $apiKey = $request->header('X-Recording-Api-Key') ?: $request->get('api_key');
        
        // Get the expected API key from environment
        $expectedApiKey = config('app.recording_api_key', env('RECORDING_API_KEY'));
        
        // Check if API key matches
        if (empty($expectedApiKey) || $apiKey !== $expectedApiKey) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or missing API key'
            ], 401);
        }

        return $next($request);
    }
}
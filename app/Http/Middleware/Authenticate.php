<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        \Illuminate\Support\Facades\Log::info('AUTH DEBUG unauthenticated redirect', [
            'url' => $request->fullUrl(),
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'session_keys' => $request->hasSession() ? array_keys($request->session()->all()) : [],
        ]);

        return $request->expectsJson() ? null : route('login');
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the Telegram module when the installation has switched the bot off.
 */
class EnsureTelegramIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::telegram(), 404, 'Telegram is disabled.');

        return $next($request);
    }
}

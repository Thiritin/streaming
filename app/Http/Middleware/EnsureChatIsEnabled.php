<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every chat route when chat is off for this request: switched off for
 * the installation in /manage > Settings, or by this viewer in /settings.
 *
 * The frontend already hides the chat UI off the same flag; this is what stops
 * a hand-crafted request from posting into a chat that was turned off.
 */
class EnsureChatIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::enabledFor('chat', $request->user()), 404, 'Chat is disabled.');

        return $next($request);
    }
}

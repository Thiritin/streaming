<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every chat route when `CHAT_ENABLED=false`.
 *
 * The frontend already hides the chat UI off the same flag; this is what stops
 * a hand-crafted request from posting into a chat the operator turned off.
 */
class EnsureChatIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('chat.enabled'), 404, 'Chat is disabled.');

        return $next($request);
    }
}

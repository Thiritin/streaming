<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the emote endpoints when emotes are off for this request, whether the
 * installation switched them off or this viewer did.
 *
 * Sits alongside chat.enabled rather than inside it: emotes can be off while
 * chat is up, and the resolved flag already folds the chat switch in.
 */
class EnsureEmotesAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::enabledFor('emotes', $request->user()), 404, 'Emotes are disabled.');

        return $next($request);
    }
}

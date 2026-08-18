<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the emote endpoints when emotes are switched off in /manage > Settings.
 *
 * Sits alongside chat.enabled rather than inside it: emotes can be off while
 * chat is up, and Features::emotes() already folds the chat switch in.
 */
class EnsureEmotesAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::emotes(), 404, 'Emotes are disabled.');

        return $next($request);
    }
}

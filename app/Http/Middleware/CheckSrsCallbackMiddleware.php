<?php

namespace App\Http\Middleware;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use App\Models\Source;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves an SRS callback came from a streaming server rather than from the internet.
 *
 * The callbacks are state-changing - on_unpublish is what takes a source off air - and
 * used to be open, so anyone who could reach the app could black out a channel with one
 * POST naming its slug. What SRS already sends is enough to close that: `param` is the
 * publisher's RTMP query string, which carries the source's stream key, and a server
 * forwarding on another's behalf carries its own shared secret. Both are checked here so
 * the origin needs no reconfiguration and no reload.
 *
 * Deliberately not on `on_publish` (`/api/srs/auth`). That one already resolves the same
 * stream key itself, and it is the single callback whose response SRS acts on: a 403
 * there rejects a live publisher. It is the wrong place to add a second gate. Everything
 * downstream of it is a notification, so refusing one can never interrupt a stream.
 */
class CheckSrsCallbackMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->authorised($request)) {
            return $next($request);
        }

        // No body: it is attacker-controlled on exactly the requests that get here.
        Log::warning('SRS callback rejected - no valid credential', [
            'path' => $request->path(),
            'stream' => $request->input('stream'),
        ]);

        return response()->json(['code' => 403], 403);
    }

    private function authorised(Request $request): bool
    {
        $params = $this->publishParams($request);

        $sharedSecret = $request->header('X-Shared-Secret') ?: ($params['shared_secret'] ?? null);

        if ($this->isActiveServer($sharedSecret)) {
            return true;
        }

        return $this->isStreamKeyFor($request->input('stream'), $params['secret'] ?? null);
    }

    /**
     * The publisher's RTMP query string, as SRS passes it on.
     *
     * @return array<string, mixed>
     */
    private function publishParams(Request $request): array
    {
        $param = $request->input('param');

        if (! is_string($param) || $param === '') {
            return [];
        }

        parse_str(ltrim($param, '?'), $params);

        return $params;
    }

    private function isActiveServer(mixed $secret): bool
    {
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        return Server::where('shared_secret', $secret)
            ->where('status', ServerStatusEnum::ACTIVE)
            ->exists();
    }

    /**
     * The stream key is encrypted at rest, so it cannot be looked up - the source is
     * resolved by the slug the callback names and its key compared against what came in.
     */
    private function isStreamKeyFor(mixed $stream, mixed $secret): bool
    {
        if (! is_string($stream) || $stream === '' || ! is_string($secret) || $secret === '') {
            return false;
        }

        $source = Source::where('slug', $stream)->first();

        if (! $source || ! is_string($source->stream_key) || $source->stream_key === '') {
            return false;
        }

        return hash_equals($source->stream_key, $secret);
    }
}

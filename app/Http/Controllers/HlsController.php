<?php

namespace App\Http\Controllers;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Helpers\IpSubnetHelper;
use App\Models\Server;
use App\Models\Source;
use App\Models\SourceUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HlsController extends Controller
{
    /**
     * Identifies a signed-out viewer across requests.
     *
     * A dedicated cookie rather than the session id, for two reasons. The session id
     * is regenerated on login and on any other `session()->regenerate()`, which would
     * silently re-pin the viewer to a different edge and double-count them for the
     * length of the heartbeat window. And touching the session on every playlist
     * refresh means a session write every two seconds per viewer, which the playlist
     * path otherwise does not need at all.
     *
     * Encrypted and signed by the `web` group like any other cookie, so it cannot be
     * pointed at another viewer's session row. Only its hash is ever stored.
     */
    private const VIEWER_COOKIE = 'viewer_id';

    private const VIEWER_COOKIE_MINUTES = 60 * 24 * 7;

    /**
     * Serve the FFmpeg-generated master.m3u8 playlist for adaptive bitrate streaming
     * FFmpeg creates perfectly synchronized segments using var_stream_map
     */
    public function master(Request $request, $stream)
    {
        // Find the source by slug
        $source = Source::where('slug', $stream)->first();

        if (! $source) {
            return response('Stream not found', 404)
                ->header('Content-Type', 'text/plain');
        }

        // Check for streamkey parameter first, then fall back to authenticated user
        $user = null;
        $streamkey = $request->get('streamkey');

        if ($streamkey) {
            // Check if it's the system streamkey first
            $systemStreamkey = config('stream.system_streamkey');
            if ($systemStreamkey && $streamkey === $systemStreamkey) {
                // For system operations, create a minimal user object
                $user = new User;
                $user->id = 0;
                $user->name = 'System';
                $user->streamkey = $streamkey;
            } else {
                // Look up user by streamkey
                $user = User::where('streamkey', $streamkey)->first();
                if (! $user) {
                    return response('Invalid streamkey', 401)
                        ->header('Content-Type', 'text/plain');
                }
            }
        } else {
            $user = Auth::user();
            if (! $user && config('auth.required')) {
                return response('Authentication required', 401)
                    ->header('Content-Type', 'text/plain');
            }
        }

        $session = $this->trackUserAccess($source, $user, $request);

        // Check for IP-based server override
        $server = $this->getServerForRequest($request, $user, $session);

        if (! $server) {
            return response('No server available', 503)
                ->header('Content-Type', 'text/plain');
        }

        $this->stampSession($session, $server);

        $port = $server->port ?? 8080;

        // Build cache key based on stream, server, and streamkey
        $cacheKey = "hls_master:{$stream}:{$server->hostname}:{$port}:".($streamkey ?? 'auth');

        // Try to get cached response
        $cachedResponse = Cache::get($cacheKey);
        if ($cachedResponse) {
            return response($cachedResponse['playlist'], 200)
                ->header('Content-Type', 'application/vnd.apple.mpegurl')
                ->header('Cache-Control', 'max-age=1')
                ->header('X-Cache', 'HIT');
        }

        // Use HTTPS for port 443, HTTP for other ports
        if ($port == 443) {
            $masterUrl = "https://{$server->hostname}/live/{$stream}_master.m3u8";
        } else {
            $masterUrl = "http://{$server->hostname}:{$port}/live/{$stream}_master.m3u8";
        }

        try {
            // Fetch the master playlist from the server
            // For HTTPS, allow self-signed certificates in development
            $httpClient = Http::timeout(3)->withHeaders($this->systemAuthHeaders());
            if (str_starts_with($masterUrl, 'https://')) {
                $httpClient = $httpClient->withOptions(['verify' => false]);
            }
            $response = $httpClient->get($masterUrl);

            if ($response->successful()) {
                $playlist = $response->body();

                // Rewrite variant URLs to use our Laravel routes and preserve streamkey
                $playlist = preg_replace_callback(
                    '/^('.preg_quote($stream, '/').'_(sd|hd|fhd)\.m3u8)$/m',
                    function ($matches) use ($streamkey) {
                        $url = '/hls/'.$matches[1];
                        // Add streamkey parameter if present
                        if ($streamkey) {
                            $url .= '?streamkey='.$streamkey;
                        }

                        return $url;
                    },
                    $playlist
                );

                // Cache the successful response for 2 seconds
                Cache::put($cacheKey, ['playlist' => $playlist], 2);

                return response($playlist, 200)
                    ->header('Content-Type', 'application/vnd.apple.mpegurl')
                    ->header('Cache-Control', 'max-age=1')
                    ->header('X-Cache', 'MISS');
            }

            // Log non-successful HTTP response
            Log::warning('Failed to fetch master playlist - HTTP error', [
                'stream' => $stream,
                'server' => $server->hostname,
                'url' => $masterUrl,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'user_id' => $user?->id,
                'streamkey' => $streamkey ?? null,
            ]);

            // Pass a 404 through rather than flattening it to 502. The edge answers
            // 404 whenever a playlist is not there right now - between publisher
            // reconnects, in the seconds before the ladder has written anything, or
            // for a stream that has simply ended. That is "not available yet", and
            // hls.js retries around it; a 502 reads as a broken server and is treated
            // far more harshly. docker/edge-nginx/hls-auth.js makes the same argument
            // about 403 versus 404 one layer down.
            return response(
                'Playlist not available',
                $response->status() === 404 ? 404 : 502,
            )->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('Failed to fetch master playlist from assigned server', [
                'server' => $server->hostname,
                'url' => $masterUrl,
                'error' => $e->getMessage(),
            ]);

            return response('Error fetching playlist', 500)
                ->header('Content-Type', 'text/plain');
        }
    }

    /**
     * Proxy variant playlist from edge server and add streamkey to TS segment URLs
     */
    public function variant(Request $request, $variant)
    {
        // Extract stream name and quality from variant (e.g., "test-stream_fhd")
        if (! preg_match('/^(.+)_(fhd|hd|sd)$/', $variant, $matches)) {
            return response('Invalid variant format', 400)
                ->header('Content-Type', 'text/plain');
        }

        $streamSlug = $matches[1];
        $quality = $matches[2];

        // Find the source
        $source = Source::where('slug', $streamSlug)->first();

        if (! $source) {
            return response('Stream not found', 404)
                ->header('Content-Type', 'text/plain');
        }

        // Check for streamkey parameter first, then fall back to authenticated user
        $user = null;
        $streamkey = $request->get('streamkey');

        if ($streamkey) {
            // Check if it's the system streamkey first
            $systemStreamkey = config('stream.system_streamkey');
            if ($systemStreamkey && $streamkey === $systemStreamkey) {
                // For system operations, create a minimal user object
                $user = new User;
                $user->id = 0;
                $user->name = 'System';
                $user->streamkey = $streamkey;
            } else {
                // Look up user by streamkey
                $user = User::where('streamkey', $streamkey)->first();
                if (! $user) {
                    return response('Invalid streamkey', 401)
                        ->header('Content-Type', 'text/plain');
                }
            }
        } else {
            $user = Auth::user();
            if (! $user && config('auth.required')) {
                return response('Authentication required', 401)
                    ->header('Content-Type', 'text/plain');
            }
            $streamkey = $user?->streamkey;
        }

        $session = $this->trackUserAccess($source, $user, $request);

        // Check for IP-based server override
        $server = $this->getServerForRequest($request, $user, $session);

        if (! $server || ! $server->hostname) {
            return response('No server available', 503)
                ->header('Content-Type', 'text/plain');
        }

        $this->stampSession($session, $server);

        $hostname = $server->hostname;
        $port = $server->port ?? 8080;

        // Build cache key based on variant, server, and streamkey
        $cacheKey = "hls_variant:{$variant}:{$hostname}:{$port}:".($streamkey ?? 'auth');

        // Try to get cached response
        $cachedResponse = Cache::get($cacheKey);
        if ($cachedResponse) {
            return response($cachedResponse['playlist'], 200)
                ->header('Content-Type', 'application/vnd.apple.mpegurl')
                ->header('Cache-Control', 'max-age=1')
                ->header('X-Cache', 'HIT');
        }

        // Fetch the variant playlist from Edge server
        // Use HTTPS for port 443, HTTP for other ports
        if ($port == 443) {
            $edgeUrl = "https://{$hostname}/live/{$variant}.m3u8";
        } else {
            $edgeUrl = "http://{$hostname}:{$port}/live/{$variant}.m3u8";
        }

        try {
            // For HTTPS, allow self-signed certificates in development
            $httpClient = Http::timeout(3)->withHeaders($this->systemAuthHeaders());
            if (str_starts_with($edgeUrl, 'https://')) {
                $httpClient = $httpClient->withOptions(['verify' => false]);
            }
            $response = $httpClient->get($edgeUrl);

            if ($response->successful()) {
                $playlist = $response->body();

                // Rewrite .ts segment URLs to use full edge server URL with streamkey
                $playlist = preg_replace_callback(
                    '/^([^#\s]+\.ts)$/m',
                    function ($matches) use ($hostname, $port, $streamkey) {
                        $segment = $matches[1];
                        // Use HTTPS for port 443, HTTP for other ports
                        if ($port == 443) {
                            $url = "https://{$hostname}/live/{$segment}";
                        } else {
                            $url = "http://{$hostname}:{$port}/live/{$segment}";
                        }
                        if ($streamkey) {
                            $url .= '?streamkey='.$streamkey;
                        }

                        return $url;
                    },
                    $playlist
                );

                // Cache the successful response for 2 seconds
                Cache::put($cacheKey, ['playlist' => $playlist], 2);

                return response($playlist, 200)
                    ->header('Content-Type', 'application/vnd.apple.mpegurl')
                    ->header('Cache-Control', 'max-age=1')
                    ->header('X-Cache', 'MISS');
            }

            // Log non-successful HTTP response
            Log::warning('Failed to fetch variant playlist - HTTP error', [
                'variant' => $variant,
                'stream' => $streamSlug,
                'quality' => $quality,
                'server' => $server->hostname,
                'url' => $edgeUrl,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'user_id' => $user?->id,
                'streamkey' => $streamkey ?? null,
            ]);

            // See master(): a missing playlist is a 404, not a fault.
            return response(
                'Playlist not available',
                $response->status() === 404 ? 404 : 502,
            )->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('Failed to fetch variant playlist from edge server', [
                'server' => $server->hostname,
                'variant' => $variant,
                'url' => $edgeUrl,
                'error' => $e->getMessage(),
            ]);

            return response('Error fetching playlist', 500)
                ->header('Content-Type', 'text/plain');
        }
    }

    /**
     * Identify this proxy to an edge server.
     *
     * Edge nginx authenticates .m3u8 as well as .ts now, so these internal
     * fetches need a credential. It goes in a header rather than the query
     * string so the URL stays byte-identical, which keeps the edge's playlist
     * cache key shared across viewers and keeps the key out of edge access logs.
     * njs recognises it locally, with no round trip back here.
     *
     * @return array<string, string>
     */
    private function systemAuthHeaders(): array
    {
        $systemStreamkey = config('stream.system_streamkey');

        return $systemStreamkey ? ['X-Stream-Key' => $systemStreamkey] : [];
    }

    /**
     * Resolve this request's viewer session, creating it on first sight.
     *
     * Returns the row so the caller can read the edge it is pinned to and stamp it
     * back once one is chosen. Null only for the system user, which is internal
     * traffic (thumbnail capture, monitoring) and not a viewer.
     *
     * Guests get a row too, keyed by a hash of their session id. They did not used to,
     * and the consequence was not a missing statistic: `UpdateServerViewerCountsJob`
     * counts these rows, so a guest raised no edge's load, and the guest branch of
     * `getServerForRequest` sends every guest to the least loaded edge. With nothing
     * ever raising that number, all guest traffic converged on a single edge.
     */
    private function trackUserAccess($source, $user, Request $request): ?SourceUser
    {
        if ($user && $user->id === 0) {
            return null;
        }

        $identity = $user
            ? ['user_id' => $user->id]
            : ['guest_key' => $this->guestKey($request)];

        // The row is read on every request, because it carries the edge assignment and
        // a stale one would send the viewer somewhere else mid-session. That is one
        // indexed select per request; the write is what actually costs, so only the
        // heartbeat is rate limited, below.
        $session = SourceUser::firstOrCreate(
            $identity + ['source_id' => $source->id, 'left_at' => null],
            [
                'joined_at' => now(),
                'last_heartbeat_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        $cacheKey = 'hls_heartbeat:'.$source->id.':'.($user?->id ?? $identity['guest_key']);

        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, true, 60);
            $session->forceFill(['last_heartbeat_at' => now()])->save();
        }

        // Stale sessions used to be swept here, on the request path, with a table-wide
        // update per heartbeat. CleanupStaleViewerSessionsJob already does exactly that
        // every minute, so this was duplicated work in the hot path.

        return $session;
    }

    /**
     * The signed-out viewer's identity, issuing one on first sight.
     *
     * The freshly generated id is used immediately as well as queued, so the very
     * first playlist request is tracked rather than being dropped while waiting for
     * the cookie to come back around.
     */
    private function guestKey(Request $request): string
    {
        $id = $request->cookie(self::VIEWER_COOKIE);

        if (! is_string($id) || strlen($id) !== 32) {
            $id = Str::random(32);

            Cookie::queue(cookie(
                self::VIEWER_COOKIE,
                $id,
                self::VIEWER_COOKIE_MINUTES,
                null,
                null,
                null,
                true,     // httpOnly; nothing in the browser needs to read this
                false,
                'lax',
            ));
        }

        // Hashed so a leak of source_users cannot be replayed as a viewer cookie.
        return hash('sha256', $id);
    }

    /**
     * Pin a resolved session to the edge it was actually served from.
     *
     * This is what makes the viewer count real: UpdateServerViewerCountsJob groups on
     * source_users.server_id, so an edge's load is the number of sessions that say they
     * are on it, whether or not those sessions belong to a signed-in user.
     */
    private function stampSession(?SourceUser $session, $server): void
    {
        // The subnet override hands back an unsaved Server standing in for an
        // appliance on the venue network. It has no id and nothing to attribute to.
        if (! $session || ! $server || ! $server->exists) {
            return;
        }

        if ($session->server_id !== $server->id) {
            $session->forceFill(['server_id' => $server->id])->save();
        }
    }

    /**
     * Get the appropriate server for the request, checking for subnet-based overrides
     */
    private function getServerForRequest(Request $request, $user, ?SourceUser $session = null)
    {
        // Check if we should use a local override based on client IP subnet
        $clientIp = $request->ip();
        $localIpv4Subnet = config('stream.local_streaming_ipv4_subnet');
        $localIpv6Subnet = config('stream.local_streaming_ipv6_subnet');
        $localHostname = config('stream.local_streaming_hostname');

        // Check if the client IP matches the configured subnets
        if ($localHostname && (
            ($localIpv4Subnet && IpSubnetHelper::isIpInSubnet($clientIp, $localIpv4Subnet)) ||
            ($localIpv6Subnet && IpSubnetHelper::isIpInSubnet($clientIp, $localIpv6Subnet))
        )) {
            return Server::where('hostname', $localHostname)->first();
        }

        // For system users, just return the first available edge server
        if ($user && $user->id === 0) {
            return Server::getActiveEdges()->first();
        }

        if (! $user) {
            return $this->guestEdge($session);
        }

        return $user->getOrAssignServer($clientIp);
    }

    /**
     * Edge for a signed-out viewer: chosen once, then kept.
     *
     * Signed-in viewers get stickiness from `users.server_id`; this is the same idea on
     * the session row. Without it the choice is remade on every request against a
     * viewer_count that refreshes every 30 seconds, so a whole show's worth of guests
     * reads the same "least loaded" answer and lands together.
     */
    private function guestEdge(?SourceUser $session)
    {
        if ($session?->server_id) {
            $pinned = Server::where('id', $session->server_id)
                ->where('status', ServerStatusEnum::ACTIVE)
                ->where('type', ServerTypeEnum::EDGE)
                ->first();

            // Falls through to a fresh pick when the pinned edge has gone away, which
            // is what moves guests off an edge being deprovisioned.
            if ($pinned) {
                return $pinned;
            }
        }

        // Same ordering as User::assignServerToUser: prefer an edge with headroom, and
        // fall back to the least loaded one rather than refusing to serve.
        $query = fn () => Server::where('status', ServerStatusEnum::ACTIVE)
            ->where('type', ServerTypeEnum::EDGE);

        return $query()->whereColumn('viewer_count', '<', 'max_clients')
            ->orderBy('viewer_count', 'asc')
            ->first()
            ?? $query()->orderBy('viewer_count', 'asc')->first();
    }
}

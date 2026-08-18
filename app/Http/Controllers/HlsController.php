<?php

namespace App\Http\Controllers;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Helpers\IpSubnetHelper;
use App\Models\EmbedKey;
use App\Models\Server;
use App\Models\Source;
use App\Models\SourceUser;
use App\Models\User;
use App\Services\PlaybackTokenService;
use App\Support\PlaybackToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
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
     * Stands in for the per-viewer credential inside a cached variant playlist.
     *
     * The credential is minted per response and substituted on the way out, so the
     * cached body is identical for every viewer of a variant. The cache key used to
     * carry the streamkey, which meant N entries of byte-identical content for N
     * viewers; now it is one entry per variant per edge.
     */
    private const CREDENTIAL_PLACEHOLDER = '__PLAYBACK_CREDENTIAL__';

    /**
     * How long a fetched playlist is handed out before the edge is asked again.
     *
     * A segment is two seconds, so this is the shortest window that never serves a
     * playlist the edge has already replaced.
     */
    private const PLAYLIST_TTL = 2;

    /**
     * How long the previous copy is kept for a caller that could not take the fetch
     * lock, or for a blip at the edge. It is only ever a cycle or two behind, and a
     * player treats an unchanged playlist as normal - which is exactly what it is.
     */
    private const PLAYLIST_STALE_TTL = 30;

    /** Below this a playlist is not worth compressing; a master is a few lines. */
    private const COMPRESS_MIN_BYTES = 1024;

    /** Upper bound on how long one in-flight upstream fetch holds the others off. */
    private const PLAYLIST_LOCK_TTL = 5;

    /**
     * How long a resolved viewer - their session row and the edge it is pinned to -
     * is reused without touching the database.
     *
     * Everything behind this used to run on every playlist poll: a select for the
     * session row, a select for the edge, and a write whenever either moved. At a two
     * second playlist that is several database round trips per viewer per second,
     * which is what actually capped how many viewers one app box could serve. The
     * window matches the heartbeat the row is written for in the first place, so
     * nothing downstream sees a difference.
     */
    private const RESOLUTION_TTL = 60;

    /**
     * How long the active edge list is reused. viewer_count only moves when
     * UpdateServerViewerCountsJob runs, every 30 seconds, so this costs no accuracy.
     */
    private const EDGE_CACHE_TTL = 10;

    /** Slug and streamkey lookups are effectively static within a show. */
    private const IDENTITY_CACHE_TTL = 30;

    /**
     * Serve the FFmpeg-generated master.m3u8 playlist for adaptive bitrate streaming
     * FFmpeg creates perfectly synchronized segments using var_stream_map
     */
    public function master(Request $request, $stream)
    {
        $source = $this->resolveSource($stream);

        if (! $source) {
            return response('Stream not found', 404)
                ->header('Content-Type', 'text/plain');
        }

        [$user, $streamkey, $rejection] = $this->identify($request, $stream);

        if ($rejection) {
            return $rejection;
        }

        $server = $this->placeViewer($request, $source, $user);

        if (! $server) {
            return response('No server available', 503)
                ->header('Content-Type', 'text/plain');
        }

        $port = $server->port ?? 8080;

        // As in variant(): the credential varies per caller, the body does not, so it
        // is substituted on the way out rather than baked into the cache key.
        $cacheKey = "hls_master:{$stream}:{$server->hostname}:{$port}";

        $embedToken = $this->embedTokenFor($request, $stream);
        $credential = match (true) {
            (bool) $streamkey => 'streamkey='.$streamkey,
            (bool) $embedToken => 't='.$embedToken,
            default => null,
        };

        $renderKey = $this->renderKey($cacheKey, $credential);
        $rendered = Cache::get($renderKey);

        // The whole hot path, when it is warm: one cache read and the bytes.
        if (is_array($rendered)) {
            return $this->playlistResponse($request, $rendered, 'HIT');
        }

        $upstreamUrl = $port == 443
            ? "https://{$server->hostname}/live/{$stream}_master.m3u8"
            : "http://{$server->hostname}:{$port}/live/{$stream}_master.m3u8";

        $result = $this->servePlaylist(
            $cacheKey,
            $upstreamUrl,
            // Rewrite variant URLs to our own routes, carrying whatever credential
            // got this request in. A display or VLC has no session cookie, so
            // dropping its token here would 401 the very next request.
            fn (string $playlist) => preg_replace_callback(
                '/^('.preg_quote($stream, '/').'_(sd|hd|fhd)\.m3u8)$/m',
                fn ($matches) => '/hls/'.$matches[1].'?'.self::CREDENTIAL_PLACEHOLDER,
                $playlist
            ),
            [
                'playlist' => 'master',
                'stream' => $stream,
                'server' => $server->hostname,
                'user_id' => $user?->id,
            ],
        );

        if ($result['body'] === null) {
            return response($result['error'], $result['status'])
                ->header('Content-Type', 'text/plain');
        }

        $rendered = $this->renderPlaylist($result['body'], $credential);

        Cache::put($renderKey, $rendered, self::PLAYLIST_TTL);

        return $this->playlistResponse($request, $rendered, $result['cache']);
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

        $source = $this->resolveSource($streamSlug);

        if (! $source) {
            return response('Stream not found', 404)
                ->header('Content-Type', 'text/plain');
        }

        [$user, $streamkey, $rejection] = $this->identify($request, $streamSlug);

        if ($rejection) {
            return $rejection;
        }

        $server = $this->placeViewer($request, $source, $user);

        if (! $server || ! $server->hostname) {
            return response('No server available', 503)
                ->header('Content-Type', 'text/plain');
        }

        $hostname = $server->hostname;
        $port = $server->port ?? 8080;

        $cacheKey = "hls_variant:{$variant}:{$hostname}:{$port}";

        $credential = $this->segmentCredential($source, $user);

        $renderKey = $this->renderKey($cacheKey, $credential);
        $rendered = Cache::get($renderKey);

        // A warm variant is a single cache read and a write of bytes that were
        // already substituted and already compressed, shared by every viewer.
        if (is_array($rendered)) {
            return $this->playlistResponse($request, $rendered, 'HIT');
        }

        $upstreamUrl = $port == 443
            ? "https://{$hostname}/live/{$variant}.m3u8"
            : "http://{$hostname}:{$port}/live/{$variant}.m3u8";

        $result = $this->servePlaylist(
            $cacheKey,
            $upstreamUrl,
            // Rewrite .ts segment URLs to the edge, with a placeholder credential
            fn (string $playlist) => preg_replace_callback(
                '/^([^#\s]+\.ts)$/m',
                function ($matches) use ($hostname, $port) {
                    $url = $port == 443
                        ? "https://{$hostname}/live/{$matches[1]}"
                        : "http://{$hostname}:{$port}/live/{$matches[1]}";

                    return $url.'?'.self::CREDENTIAL_PLACEHOLDER;
                },
                $playlist
            ),
            [
                'playlist' => 'variant',
                'variant' => $variant,
                'stream' => $streamSlug,
                'quality' => $quality,
                'server' => $hostname,
                'user_id' => $user?->id,
            ],
        );

        if ($result['body'] === null) {
            return response($result['error'], $result['status'])
                ->header('Content-Type', 'text/plain');
        }

        $rendered = $this->renderPlaylist($result['body'], $credential);

        Cache::put($renderKey, $rendered, self::PLAYLIST_TTL);

        return $this->playlistResponse($request, $rendered, $result['cache']);
    }

    /**
     * The source behind a slug, without a select per playlist poll.
     */
    private function resolveSource(string $slug): ?Source
    {
        $source = Cache::remember(
            'hls_source:'.$slug,
            self::IDENTITY_CACHE_TTL,
            fn () => Source::where('slug', $slug)->first() ?? false,
        );

        return $source ?: null;
    }

    /**
     * Who is asking, and whether they may be here at all.
     *
     * The streamkey still works as a credential here even though the edges no longer
     * accept it: it is resolved once against the database and the segment URLs the
     * viewer gets back carry a short-lived playback token instead. That is what keeps
     * a VLC or Smart TV URL a single permanent string without putting PHP in front of
     * every segment.
     *
     * @return array{0: ?User, 1: ?string, 2: ?Response}
     */
    private function identify(Request $request, string $stream): array
    {
        $streamkey = $request->get('streamkey');

        if ($streamkey) {
            $systemStreamkey = config('stream.system_streamkey');

            if ($systemStreamkey && hash_equals((string) $systemStreamkey, (string) $streamkey)) {
                // Internal callers are not viewers; a stand-in user carries that.
                $system = new User;
                $system->id = 0;
                $system->name = 'System';
                $system->streamkey = $streamkey;

                return [$system, $streamkey, null];
            }

            $user = $this->userForStreamkey($streamkey);

            if (! $user) {
                return [null, null, response('Invalid streamkey', 401)
                    ->header('Content-Type', 'text/plain')];
            }

            return [$user, $streamkey, null];
        }

        $user = Auth::user();

        // An embed key stands in for being signed in: a display or a VLC window has
        // no session cookie, only the token in the URL.
        if (! $user && ! $this->embedKeyAuthorises($request, $stream) && config('auth.required')) {
            return [null, null, response('Authentication required', 401)
                ->header('Content-Type', 'text/plain')];
        }

        return [$user, null, null];
    }

    private function userForStreamkey(string $streamkey): ?User
    {
        $user = Cache::remember(
            'hls_streamkey:'.hash('sha256', $streamkey),
            self::IDENTITY_CACHE_TTL,
            fn () => User::where('streamkey', $streamkey)->first() ?? false,
        );

        return $user ?: null;
    }

    /**
     * Fetch a playlist, collapsing a stampede into a single upstream call.
     *
     * Hundreds of players poll the same playlist inside the same second. Without a
     * lock, every request that arrives while the entry is cold opens its own
     * connection to the edge, so each expiry costs one upstream fetch per viewer
     * rather than one per playlist. Whoever loses the race is served the copy from
     * the previous cycle, which is what the winner is about to publish anyway.
     *
     * @param  \Closure(string): string  $rewrite
     * @param  array<string, mixed>  $context
     * @return array{body: ?string, status: int, cache: string, error: ?string}
     */
    private function servePlaylist(string $cacheKey, string $upstreamUrl, \Closure $rewrite, array $context): array
    {
        $staleKey = $cacheKey.':stale';
        $lockKey = $cacheKey.':lock';

        $fresh = Cache::get($cacheKey);

        if (is_string($fresh)) {
            return ['body' => $fresh, 'status' => 200, 'cache' => 'HIT', 'error' => null];
        }

        if (! Cache::add($lockKey, 1, self::PLAYLIST_LOCK_TTL)) {
            $stale = Cache::get($staleKey);

            // Nothing stale to fall back on means this is the first fetch of this
            // playlist, so waiting behind the lock would only turn a cold start into
            // a stall. Fetch anyway; it happens once.
            if (is_string($stale)) {
                return ['body' => $stale, 'status' => 200, 'cache' => 'STALE', 'error' => null];
            }
        }

        try {
            $httpClient = Http::timeout(3)->withHeaders($this->systemAuthHeaders());

            if (str_starts_with($upstreamUrl, 'https://')) {
                // Allow self-signed certificates; edges terminate TLS themselves.
                $httpClient = $httpClient->withOptions(['verify' => false]);
            }

            $response = $httpClient->get($upstreamUrl);

            if ($response->successful()) {
                $playlist = $rewrite($response->body());

                Cache::put($cacheKey, $playlist, self::PLAYLIST_TTL);
                Cache::put($staleKey, $playlist, self::PLAYLIST_STALE_TTL);

                return ['body' => $playlist, 'status' => 200, 'cache' => 'MISS', 'error' => null];
            }

            Log::warning('Failed to fetch playlist - HTTP error', $context + [
                'url' => $upstreamUrl,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
            ]);

            $stale = Cache::get($staleKey);

            // A fault at the edge is worth papering over with the last good copy; a
            // 404 is not, because that is how a stream that has ended reads.
            if ($response->status() !== 404 && is_string($stale)) {
                return ['body' => $stale, 'status' => 200, 'cache' => 'STALE', 'error' => null];
            }

            // Pass a 404 through rather than flattening it to 502. The edge answers
            // 404 whenever a playlist is not there right now - between publisher
            // reconnects, in the seconds before the ladder has written anything, or
            // for a stream that has simply ended. That is "not available yet", and
            // hls.js retries around it; a 502 reads as a broken server and is treated
            // far more harshly. docker/edge-nginx/hls-auth.js makes the same argument
            // about 403 versus 404 one layer down.
            return [
                'body' => null,
                'status' => $response->status() === 404 ? 404 : 502,
                'cache' => 'MISS',
                'error' => 'Playlist not available',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch playlist from edge server', $context + [
                'url' => $upstreamUrl,
                'error' => $e->getMessage(),
            ]);

            $stale = Cache::get($staleKey);

            if (is_string($stale)) {
                return ['body' => $stale, 'status' => 200, 'cache' => 'STALE', 'error' => null];
            }

            return [
                'body' => null,
                'status' => 500,
                'cache' => 'MISS',
                'error' => 'Error fetching playlist',
            ];
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Pick the edge that serves this request, and keep the viewer's session row alive.
     *
     * Both answers are cached together for RESOLUTION_TTL, so a viewer costs one
     * select and at most two writes a minute instead of several per second. See the
     * constant for what that replaced.
     */
    private function placeViewer(Request $request, Source $source, ?User $user): ?Server
    {
        $override = $this->localOverrideEdge($request);

        // Internal callers (thumbnail capture, monitoring) are not viewers and get
        // no session row.
        if ($user && $user->id === 0) {
            return $override ?? $this->activeEdges()->first();
        }

        // Resolved once and handed down: guestKey() issues the cookie on first sight,
        // so calling it twice in a request would queue two of them.
        $guestKey = $user ? null : $this->guestKey($request);
        $identity = $user ? 'u:'.$user->id : 'g:'.$guestKey;
        $cacheKey = "hls_viewer:{$source->id}:{$identity}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            $pinned = $override ?? $this->activeEdges()->get($cached['server_id']);

            // A pinned edge that has gone away falls through to a fresh pick, which
            // is what moves viewers off an edge being deprovisioned.
            if ($pinned) {
                return $pinned;
            }
        }

        $session = $this->trackUserAccess($source, $user, $request, $guestKey);
        $server = $override ?? $this->getServerForRequest($user, $session);

        if (! $server) {
            return null;
        }

        $this->stampSession($session, $server);

        Cache::put(
            $cacheKey,
            ['server_id' => $server->exists ? $server->getKey() : null],
            self::RESOLUTION_TTL,
        );

        return $server;
    }

    /**
     * Active edges keyed by id, read once per window rather than once per request.
     *
     * @return Collection<int, Server>
     */
    private function activeEdges()
    {
        return Cache::remember(
            'hls_active_edges',
            self::EDGE_CACHE_TTL,
            fn () => Server::where('status', ServerStatusEnum::ACTIVE)
                ->where('type', ServerTypeEnum::EDGE)
                ->orderBy('viewer_count', 'asc')
                ->get()
                ->keyBy('id'),
        );
    }

    /**
     * The appliance on the venue network, when the caller is sitting behind it.
     */
    private function localOverrideEdge(Request $request): ?Server
    {
        $localHostname = config('stream.local_streaming_hostname');

        if (! $localHostname) {
            return null;
        }

        $clientIp = $request->ip();
        $ipv4 = config('stream.local_streaming_ipv4_subnet');
        $ipv6 = config('stream.local_streaming_ipv6_subnet');

        $inSubnet = ($ipv4 && IpSubnetHelper::isIpInSubnet($clientIp, $ipv4))
            || ($ipv6 && IpSubnetHelper::isIpInSubnet($clientIp, $ipv6));

        if (! $inSubnet) {
            return null;
        }

        $server = Cache::remember(
            'hls_local_edge:'.$localHostname,
            self::EDGE_CACHE_TTL,
            fn () => Server::where('hostname', $localHostname)->first() ?? false,
        );

        return $server ?: null;
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
     * Whether this request carries a valid embed key token for this source.
     *
     * Displays and VLC have no session cookie, so a token in the query string is
     * their whole identity. It is checked here rather than only at the edge because
     * the `kid` claim can be looked up in the database on the way past, which makes
     * revoking a key take effect on the next playlist refresh instead of needing an
     * allowlist pushed out to every edge.
     *
     * The token itself never reaches the edge. Segments get a short-lived viewer
     * token like any other viewer, minted below, so a leaked embed URL cannot be
     * turned into a permanent segment credential.
     */
    private function embedKeyAuthorises(Request $request, string $stream): bool
    {
        $verified = $this->verifiedEmbedToken($request, $stream);

        if ($verified === null || $verified->keyId === null) {
            return false;
        }

        // The row is cached, not the verdict, so a wall of displays does not put a
        // select in front of every playlist poll while sign-out still decides per
        // token. EmbedKey forgets this entry whenever it is written, so revoking or
        // signing out a key takes effect on the next request rather than at the end
        // of the window.
        $key = Cache::remember(
            'hls_embed_key:'.$verified->keyId,
            self::RESOLUTION_TTL,
            fn () => EmbedKey::query()->whereKey($verified->keyId)->first() ?? false,
        );

        // A signed-out key still exists, so the row alone is not the answer: a token
        // minted before the sign-out is one of the ones it was meant to cut off.
        if (! $key || ! $key->acceptsSessionFrom($verified->issuedAt)) {
            return false;
        }

        // last_used_at is a "when was this screen last seen" stamp, not an audit log,
        // so one write a window says the same thing as one per poll.
        if (Cache::add('hls_embed_touch:'.$verified->keyId, true, self::RESOLUTION_TTL)) {
            $key->touchUsage($request);
        }

        return true;
    }

    /**
     * The raw embed token this request presented, when it verifies for this source.
     *
     * Returned as the original string rather than re-minted, so the same token flows
     * from the master playlist into the variant requests that follow it.
     */
    private function embedTokenFor(Request $request, string $stream): ?string
    {
        $token = $request->get('t');

        return $this->verifiedEmbedToken($request, $stream) === null ? null : $token;
    }

    private function verifiedEmbedToken(Request $request, string $stream): ?PlaybackToken
    {
        $token = $request->get('t');

        if (! is_string($token) || $token === '') {
            return null;
        }

        $verified = app(PlaybackTokenService::class)->tryVerify($token, $stream);

        return ($verified !== null && $verified->isEmbed()) ? $verified : null;
    }

    /**
     * Query string a segment URL carries so the edge can authorise it locally.
     *
     * A short-lived signed token, verified by njs in-process with no round trip back
     * here. It replaced the per-user streamkey, which could only be resolved against
     * the database and so cost a PHP request per segment.
     *
     * Shared by every viewer of a source for a bucket at a time rather than minted
     * per viewer: the edge only ever checks the source binding and the expiry, so
     * per-viewer tokens bought nothing enforceable and cost a uniquely rendered
     * playlist per request. See PlaybackTokenService::issueSegmentToken.
     *
     * Internal callers (thumbnail capture, monitoring) keep the system streamkey,
     * which njs recognises directly.
     *
     * Null when no secret is configured, which leaves the URLs bare. Only reachable
     * on an installation that has not been given the token secrets at all.
     */
    private function segmentCredential(Source $source, ?User $user): ?string
    {
        if ($user && $user->id === 0) {
            $systemStreamkey = config('stream.system_streamkey');

            return $systemStreamkey ? 'streamkey='.$systemStreamkey : null;
        }

        $tokens = app(PlaybackTokenService::class);

        if (! $tokens->isConfigured()) {
            return null;
        }

        return 't='.$tokens->issueSegmentToken($source);
    }

    /**
     * Where the finished bytes for one playlist and one credential live.
     *
     * The credential is part of the key rather than the body being rebuilt per
     * request: viewers share one, and the few callers that do not - internal
     * fetches, a display carrying an embed token - get an entry of their own.
     */
    private function renderKey(string $cacheKey, ?string $credential): string
    {
        return $cacheKey.':r:'.substr(hash('sha256', (string) $credential), 0, 16);
    }

    /**
     * Substitute the credential once and compress once.
     *
     * A 60 minute DVR window is 1800 segments, so a variant playlist is several
     * hundred kilobytes with the token repeated on every line. Both the substitution
     * and the gzip used to be paid per request; with a shared credential they are
     * paid once per PLAYLIST_TTL and the result is handed to everyone. The repeated
     * token compresses to almost nothing, which is why the wire cost collapses.
     *
     * Both encodings are built up front rather than on demand, because the choice
     * belongs to the caller and the body is shared: a 389KB playlist is 5.8KB
     * gzipped and 2.8KB brotlied, for about 1.1ms and 1.9ms once per TTL.
     *
     * @return array{raw: string, gzip: ?string, br: ?string}
     */
    private function renderPlaylist(string $body, ?string $credential): array
    {
        $raw = $this->applyCredential($body, $credential);
        $worthCompressing = strlen($raw) >= self::COMPRESS_MIN_BYTES;

        return [
            'raw' => $raw,
            'gzip' => $worthCompressing ? gzencode($raw, 4) : null,
            // ext-brotli only ships in the production image, so this stays null
            // locally and in CI and those callers fall through to gzip.
            'br' => $worthCompressing && function_exists('brotli_compress')
                ? brotli_compress($raw, 4)
                : null,
        ];
    }

    /**
     * @param  array{raw: string, gzip: ?string}  $rendered
     */
    private function playlistResponse(Request $request, array $rendered, string $cacheState)
    {
        $accepted = $this->acceptedEncodings($request);

        // Brotli first: it is about half the gzip size on a token-per-line playlist.
        // The null coalesce covers entries cached by an older revision.
        [$encoding, $body] = match (true) {
            ($rendered['br'] ?? null) !== null && in_array('br', $accepted, true) => ['br', $rendered['br']],
            $rendered['gzip'] !== null && in_array('gzip', $accepted, true) => ['gzip', $rendered['gzip']],
            default => [null, $rendered['raw']],
        };

        $response = response($body, 200)
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'max-age=1')
            ->header('Vary', 'Accept-Encoding')
            ->header('X-Cache', $cacheState);

        if ($encoding !== null) {
            $response->header('Content-Encoding', $encoding);
        }

        return $response;
    }

    /**
     * Content codings the caller will take, with explicit q=0 refusals dropped.
     *
     * @return list<string>
     */
    private function acceptedEncodings(Request $request): array
    {
        $accepted = [];

        foreach (explode(',', strtolower((string) $request->header('Accept-Encoding', ''))) as $candidate) {
            $parameters = array_map('trim', explode(';', $candidate));
            $coding = array_shift($parameters);

            if ($coding === '') {
                continue;
            }

            foreach ($parameters as $parameter) {
                if (preg_match('/^q=0(\.0+)?$/', $parameter)) {
                    continue 2;
                }
            }

            $accepted[] = $coding;
        }

        return $accepted;
    }

    /**
     * Substitute this viewer's credential into a cached playlist body.
     */
    private function applyCredential(string $playlist, ?string $credential): string
    {
        return str_replace(
            '?'.self::CREDENTIAL_PLACEHOLDER,
            $credential === null ? '' : '?'.$credential,
            $playlist,
        );
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
    private function trackUserAccess($source, $user, Request $request, ?string $guestKey = null): ?SourceUser
    {
        if ($user && $user->id === 0) {
            return null;
        }

        $identity = $user
            ? ['user_id' => $user->id]
            : ['guest_key' => $guestKey ?? $this->guestKey($request)];

        // The edge assignment lives on this row, so it is read rather than assumed.
        // placeViewer caches the answer, so this select happens once a minute per
        // viewer instead of once per poll.
        $session = SourceUser::firstOrCreate(
            $identity + ['source_id' => $source->id, 'left_at' => null],
            [
                'joined_at' => now(),
                'last_heartbeat_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        // Reached once per RESOLUTION_TTL per viewer, because placeViewer is what
        // rate limits it now, so the heartbeat is written rather than gated a second
        // time. A row that was just created already carries one.
        if (! $session->wasRecentlyCreated) {
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
     * The cold-path edge pick: what to do when nothing is pinned yet.
     *
     * The subnet override is applied by placeViewer before this is reached, so a
     * venue appliance never depends on getting this far.
     */
    private function getServerForRequest($user, ?SourceUser $session = null)
    {
        // For system users, just return the first available edge server
        if ($user && $user->id === 0) {
            return $this->activeEdges()->first();
        }

        return $this->edgeForSession($session);
    }

    /**
     * Edge for a viewer: chosen once, then kept on the session row.
     *
     * Signed-in viewers used to be pinned by `users.server_id` instead, which was a
     * second answer to the same question and the one that scaled with the size of the
     * user table rather than the number of people watching. `source_users` already
     * carries the pin for guests, and `UpdateServerViewerCountsJob` already reads an
     * edge's load from it, so it is the only pin that has to exist. A viewer who is
     * not watching now holds no assignment at all.
     *
     * Stickiness matters as much as the choice: without it the pick is remade on every
     * request against a viewer_count that refreshes every 30 seconds, so a whole show's
     * worth of viewers reads the same "least loaded" answer and lands together.
     */
    private function edgeForSession(?SourceUser $session)
    {
        $edges = $this->activeEdges();

        if ($session?->server_id && $edges->has($session->server_id)) {
            return $edges->get($session->server_id);
        }

        // Prefer an edge with headroom and fall back to the least loaded one rather
        // than refusing to serve. The list is already sorted by viewer_count, so the
        // first match is the least loaded.
        return $edges->first(fn (Server $edge) => $edge->viewer_count < $edge->max_clients)
            ?? $edges->first();
    }
}

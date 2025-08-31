<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Source;
use App\Models\SourceUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Helpers\IpSubnetHelper;

class HlsController extends Controller
{
    /**
     * Serve the FFmpeg-generated master.m3u8 playlist for adaptive bitrate streaming
     * FFmpeg creates perfectly synchronized segments using var_stream_map
     */
    public function master(Request $request, $stream)
    {
        // Find the source by slug
        $source = Source::where('slug', $stream)->first();

        if (!$source) {
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
                $user = new User();
                $user->id = 0;
                $user->name = 'System';
                $user->streamkey = $streamkey;
            } else {
                // Look up user by streamkey
                $user = User::where('streamkey', $streamkey)->first();
                if (!$user) {
                    return response('Invalid streamkey', 401)
                        ->header('Content-Type', 'text/plain');
                }
            }
        } else {
            $user = Auth::user();
            if (!$user) {
                return response('Authentication required', 401)
                    ->header('Content-Type', 'text/plain');
            }
        }

        $this->trackUserAccess($source, $user, $request);

        // Check for IP-based server override
        $server = $this->getServerForRequest($request, $user);
        $port = $server->port ?? 8080;

        // Use HTTPS for port 443, HTTP for other ports
        if ($port == 443) {
            $masterUrl = "https://{$server->hostname}/live/{$stream}_master.m3u8";
        } else {
            $masterUrl = "http://{$server->hostname}:{$port}/live/{$stream}_master.m3u8";
        }

        try {
            // Fetch the master playlist from the server
            // For HTTPS, allow self-signed certificates in development
            $httpClient = Http::timeout(3);
            if (str_starts_with($masterUrl, 'https://')) {
                $httpClient = $httpClient->withOptions(['verify' => false]);
            }
            $response = $httpClient->get($masterUrl);

            if ($response->successful()) {
                $playlist = $response->body();

                // Rewrite variant URLs to use our Laravel routes and preserve streamkey
                $playlist = preg_replace_callback(
                    '/^(' . preg_quote($stream, '/') . '_(sd|hd|fhd)\.m3u8)$/m',
                    function($matches) use ($streamkey) {
                        $url = '/hls/' . $matches[1];
                        // Add streamkey parameter if present
                        if ($streamkey) {
                            $url .= '?streamkey=' . $streamkey;
                        }
                        return $url;
                    },
                    $playlist
                );

                return response($playlist, 200)
                    ->header('Content-Type', 'application/vnd.apple.mpegurl')
                    ->header('Cache-Control', 'max-age=1');
            }

            // Log non-successful HTTP response
            Log::warning('Failed to fetch master playlist - HTTP error', [
                'stream' => $stream,
                'server' => $server->hostname,
                'url' => $masterUrl,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'user_id' => $user->id,
                'streamkey' => $streamkey ?? null,
            ]);

            return response('Failed to fetch playlist', 502)
                ->header('Content-Type', 'text/plain');

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
        if (!preg_match('/^(.+)_(fhd|hd|sd)$/', $variant, $matches)) {
            return response('Invalid variant format', 400)
                ->header('Content-Type', 'text/plain');
        }

        $streamSlug = $matches[1];
        $quality = $matches[2];

        // Find the source
        $source = Source::where('slug', $streamSlug)->first();

        if (!$source) {
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
                $user = new User();
                $user->id = 0;
                $user->name = 'System';
                $user->streamkey = $streamkey;
            } else {
                // Look up user by streamkey
                $user = User::where('streamkey', $streamkey)->first();
                if (!$user) {
                    return response('Invalid streamkey', 401)
                        ->header('Content-Type', 'text/plain');
                }
            }
        } else {
            $user = Auth::user();
            if (!$user) {
                return response('Authentication required', 401)
                    ->header('Content-Type', 'text/plain');
            }
            $streamkey = $user->streamkey;
        }

        $this->trackUserAccess($source, $user, $request);

        // Check for IP-based server override
        $server = $this->getServerForRequest($request, $user);

        if (!$server || !$server->hostname) {
            return response('No server available', 503)
                ->header('Content-Type', 'text/plain');
        }

        $hostname = $server->hostname;
        $port = $server->port ?? 8080;

        // Fetch the variant playlist from Edge server
        // Use HTTPS for port 443, HTTP for other ports
        if ($port == 443) {
            $edgeUrl = "https://{$hostname}/live/{$variant}.m3u8";
        } else {
            $edgeUrl = "http://{$hostname}:{$port}/live/{$variant}.m3u8";
        }

        try {
            // For HTTPS, allow self-signed certificates in development
            $httpClient = Http::timeout(3);
            if (str_starts_with($edgeUrl, 'https://')) {
                $httpClient = $httpClient->withOptions(['verify' => false]);
            }
            $response = $httpClient->get($edgeUrl);

            if ($response->successful()) {
                $playlist = $response->body();

                // Rewrite .ts segment URLs to use full edge server URL with streamkey
                $playlist = preg_replace_callback(
                    '/^([^#\s]+\.ts)$/m',
                    function($matches) use ($hostname, $port, $streamkey) {
                        $segment = $matches[1];
                        // Use HTTPS for port 443, HTTP for other ports
                        if ($port == 443) {
                            $url = "https://{$hostname}/live/{$segment}";
                        } else {
                            $url = "http://{$hostname}:{$port}/live/{$segment}";
                        }
                        if ($streamkey) {
                            $url .= '?streamkey=' . $streamkey;
                        }
                        return $url;
                    },
                    $playlist
                );

                return response($playlist, 200)
                    ->header('Content-Type', 'application/vnd.apple.mpegurl')
                    ->header('Cache-Control', 'max-age=1');
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
                'user_id' => $user->id,
                'streamkey' => $streamkey ?? null,
            ]);

            return response('Failed to fetch playlist', 502)
                ->header('Content-Type', 'text/plain');

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
     * Track user access to streams
     */
    private function trackUserAccess($source, $user, $request)
    {
        // Skip tracking for system user
        if ($user->id === 0) {
            return;
        }

        // Build cache key for this user-source combination
        $cacheKey = "hls_heartbeat:{$source->id}:{$user->id}";

        // Check if we should skip heartbeat update (cache key exists = updated recently)
        if (Cache::has($cacheKey)) {
            // Skip - heartbeat was updated recently
            return;
        }

        // Set cache key for 60 seconds - prevents database updates for this duration
        Cache::put($cacheKey, true, 60);

        // Find or create viewer session
        $session = SourceUser::firstOrCreate(
            [
                'source_id' => $source->id,
                'user_id' => $user->id,
                'left_at' => null,
            ],
            [
                'joined_at' => now(),
                'last_heartbeat_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

        // Update heartbeat
        $session->update([
            'last_heartbeat_at' => now(),
        ]);

        // Clean up stale sessions (heartbeat older than 3 minutes)
        // This cleanup also runs only when we update heartbeat to reduce DB load
        SourceUser::where('source_id', $source->id)
            ->whereNull('left_at')
            ->where('last_heartbeat_at', '<', now()->subMinutes(3))
            ->update(['left_at' => now()]);

        Log::info('User heartbeat updated', [
            'user_id' => $user->id,
            'source_id' => $source->id,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Get the appropriate server for the request, checking for subnet-based overrides
     */
    private function getServerForRequest(Request $request, $user)
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

        return $user->getOrAssignServer($clientIp);
    }
}

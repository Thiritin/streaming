<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\SourceUser;
use App\Models\Server;
use App\Enum\ServerTypeEnum;
use App\Enum\ServerStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

        // Track user access
        $user = Auth::user();
        if ($user) {
            $this->trackUserAccess($source, $user, $request);
        }

        // Get edge server to fetch master playlist from
        $edgeServer = $this->getEdgeServer($request);

        // Try to fetch FFmpeg-generated master playlist from edge server
        $edgeMasterUrl = "http://{$edgeServer->hostname}:8081/live/{$stream}_master.m3u8";

        try {
            $response = Http::timeout(5)->get($edgeMasterUrl);

            if ($response->successful()) {
                // Read the FFmpeg-generated master playlist from edge
                $content = $response->body();

                // Fix the variant URLs to use our Laravel routes (no direct file references)
                // FFmpeg generates: test-stream_sd.m3u8, test-stream_hd.m3u8, etc.
                // We need: /hls/test-stream_sd.m3u8, /hls/test-stream_hd.m3u8, etc.
                $content = preg_replace(
                    '/^(' . preg_quote($stream, '/') . '_(sd|hd|fhd)\.m3u8)$/m',
                    '/hls/$1',
                    $content
                );

                return response($content, 200)
                    ->header('Content-Type', 'application/vnd.apple.mpegurl')
                    ->header('Cache-Control', 'max-age=1')
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Range')
                    ->header('Access-Control-Expose-Headers', 'Content-Length, Content-Range');
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch master playlist from edge', [
                'url' => $edgeMasterUrl,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: Build master playlist if FFmpeg version doesn't exist on edge
        $playlist = "#EXTM3U\n";
        $playlist .= "#EXT-X-VERSION:6\n";

        // Full HD variant - NO streamkey in master playlist
        $playlist .= "#EXT-X-STREAM-INF:BANDWIDTH=6811200,RESOLUTION=1920x1080,CODECS=\"avc1.4d4028,mp4a.40.2\"\n";
        $playlist .= "/hls/{$stream}_fhd.m3u8\n\n";

        // HD variant - NO streamkey in master playlist
        $playlist .= "#EXT-X-STREAM-INF:BANDWIDTH=3476000,RESOLUTION=1280x720,CODECS=\"avc1.4d401f,mp4a.40.2\"\n";
        $playlist .= "/hls/{$stream}_hd.m3u8\n\n";

        // SD variant - NO streamkey in master playlist
        $playlist .= "#EXT-X-STREAM-INF:BANDWIDTH=1790800,RESOLUTION=854x480,CODECS=\"avc1.42c01f,mp4a.40.2\"\n";
        $playlist .= "/hls/{$stream}_sd.m3u8\n";

        return response($playlist, 200)
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Range')
            ->header('Access-Control-Expose-Headers', 'Content-Length, Content-Range');
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

        // Track user access and get streamkey
        $user = Auth::user();
        $streamkey = null;
        if ($user) {
            $this->trackUserAccess($source, $user, $request);
            $streamkey = $user->streamkey;
        }

        // Get edge server
        $edgeServer = $this->getEdgeServer($request);

        // Fetch the variant playlist from edge server
        $edgeUrl = "http://{$edgeServer->hostname}:{$edgeServer->port}/live/{$variant}.m3u8";

        try {
            $response = Http::timeout(5)->get($edgeUrl);

            if (!$response->successful()) {
                Log::warning('Failed to fetch variant playlist from edge', [
                    'url' => $edgeUrl,
                    'status' => $response->status(),
                ]);
                return response('Failed to fetch playlist', 502)
                    ->header('Content-Type', 'text/plain');
            }

            $playlist = $response->body();

            // Build the base URL for TS segments on the edge server
            $edgeBaseUrl = "http://{$edgeServer->hostname}:{$edgeServer->port}/live";

            // Rewrite TS segment URLs to use absolute edge server URLs with optional streamkey
            $playlist = preg_replace_callback(
                '/^([^#].+\.ts)(\?.*)?$/m',
                function ($matches) use ($edgeBaseUrl, $streamkey) {
                    $tsFile = $matches[1];
                    $existingParams = $matches[2] ?? '';

                    // Build absolute URL to edge server
                    $absoluteUrl = "{$edgeBaseUrl}/{$tsFile}";

                    // Add streamkey if available
                    if ($streamkey) {
                        if ($existingParams) {
                            $absoluteUrl .= $existingParams . '&streamkey=' . $streamkey;
                        } else {
                            $absoluteUrl .= '?streamkey=' . $streamkey;
                        }
                    } else if ($existingParams) {
                        $absoluteUrl .= $existingParams;
                    }

                    return $absoluteUrl;
                },
                $playlist
            );

            return response($playlist, 200)
                ->header('Content-Type', 'application/vnd.apple.mpegurl')
                ->header('Cache-Control', 'max-age=2')
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Range')
                ->header('Access-Control-Expose-Headers', 'Content-Length, Content-Range');

        } catch (\Exception $e) {
            Log::error('Error fetching variant playlist', [
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
     * Get the appropriate edge server for the request
     */
    private function getEdgeServer($request)
    {
        // For now, just get any active edge server
        // In production, this could use geo-location, load balancing, etc.
        $edgeServer = Server::where('type', ServerTypeEnum::EDGE)
            ->where('status', ServerStatusEnum::ACTIVE)
            ->first();

        // Fallback to local if no edge server
        if (!$edgeServer) {
            return (object) [
                'hostname' => 'localhost',
                'port' => 8081,
            ];
        }

        return $edgeServer;
    }

}

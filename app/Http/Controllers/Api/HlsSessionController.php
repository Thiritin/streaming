<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class HlsSessionController extends Controller
{
    /**
     * Authenticate HLS viewer request from edge server
     * Called by nginx auth_request directive
     */
    public function auth(Request $request)
    {
        // Get request details
        $originalUri = $request->header('X-Original-URI');
        $realIp = $request->header('X-Real-IP', $request->ip());
        $edgeServerId = $request->header('X-Edge-Server');
        
        $userId = null;
        $userName = null;
        $user = null;
        $hlsContext = null;
        
        // Parse stream slug from URI first to create cache keys
        // Format: /live/{slug}_quality.m3u8 or /live/{slug}_quality_segment.ts or /live/{slug}_master.m3u8
        if (!preg_match('#^/live/([^/_]+?)(?:_(?:master|fhd|hd|sd|ld))?(?:\.|_)#', $originalUri, $slugMatches)) {
            Log::warning('Invalid HLS URI format', ['uri' => $originalUri]);
            return response()->json(['error' => 'Invalid URI'], 403);
        }
        
        $streamSlug = $slugMatches[1];
        
        // Extract HLS context if present (from SRS edge server)
        if (preg_match('/[?&]hls_ctx=([^&]+)/', $originalUri, $ctxMatches)) {
            $hlsContext = $ctxMatches[1];
            
            // Check if we have stored user info for this HLS context
            $storedUserInfo = Cache::get("hls_context:{$hlsContext}");
            if ($storedUserInfo) {
                $userId = $storedUserInfo['user_id'];
                $userName = $storedUserInfo['user_name'];
            }
        }
        
        // Check for session ID or streamkey in the URL parameters
        if (preg_match('/[?&](session_id|streamkey)=([^&]+)/', $originalUri, $matches)) {
            $paramName = $matches[1];
            $tokenValue = $matches[2];
            
            // Check if it's the system streamkey (for thumbnails, monitoring, etc.)
            $systemStreamkey = config('stream.system_streamkey');
            if ($paramName === 'streamkey' && $systemStreamkey && $tokenValue === $systemStreamkey) {
                // Return success for system operations (no logging for performance)
                return response('', 200)
                    ->header('X-Session-Id', 'system');
            }
            
            // Check if it's a user's streamkey
            if ($paramName === 'streamkey' || $paramName === 'session_id') {
                // Cache user lookup for 5 minutes to avoid database queries
                $user = Cache::remember("user:streamkey:{$tokenValue}", 300, function() use ($tokenValue) {
                    return \App\Models\User::where('streamkey', $tokenValue)->first();
                });
                if ($user) {
                    $userId = $user->id;
                    $userName = $user->name;
                    
                    // Store user info with HLS context for segment requests
                    if ($hlsContext) {
                        Cache::put("hls_context:{$hlsContext}", [
                            'user_id' => $userId,
                            'user_name' => $userName,
                            'streamkey' => $tokenValue,
                        ], now()->addHours(2));
                    }
                    
                    // Also store by IP as fallback
                    $sessionKey = "hls_user:{$realIp}:{$streamSlug}";
                    Cache::put($sessionKey, [
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'streamkey' => $tokenValue,
                    ], now()->addMinutes(10));
                }
            }
        } else if (!$userId) {
            // For segment requests without streamkey and no hls_ctx match, check cache by IP+stream
            $sessionKey = "hls_user:{$realIp}:{$streamSlug}";
            $storedUserInfo = Cache::get($sessionKey);
            if ($storedUserInfo) {
                $userId = $storedUserInfo['user_id'];
                $userName = $storedUserInfo['user_name'];
                // Refresh the cache TTL
                Cache::put($sessionKey, $storedUserInfo, now()->addMinutes(10));
            }
        }
        
        // Check if source exists and is online (cached for 10 seconds)
        $source = Cache::remember("source:slug:{$streamSlug}", 10, function() use ($streamSlug) {
            return Source::where('slug', $streamSlug)->first();
        });
        
        if (!$source) {
            Log::warning('Source not found for HLS request', ['slug' => $streamSlug]);
            return response()->json(['error' => 'Stream not found'], 404);
        }
        
        if ($source->status !== \App\Enum\SourceStatusEnum::ONLINE) {
            Log::info('Source offline for HLS request', [
                'slug' => $streamSlug,
                'status' => $source->status->value,
            ]);
            return response()->json(['error' => 'Stream offline'], 404);
        }
        
        // Create or retrieve session ID for this viewer
        $sessionId = $this->getOrCreateSession($realIp, $streamSlug, $edgeServerId, $userId);
        
        // Return success with session ID
        return response()
            ->json(['status' => 'ok'])
            ->header('X-Session-Id', $sessionId);
    }
    
    /**
     * Handle heartbeat from edge server with viewer counts
     */
    public function heartbeat(Request $request)
    {
        $request->validate([
            'server_id' => 'required|string',
            'viewer_count' => 'required|integer|min:0',
            'streams' => 'array',
            'timestamp' => 'required|date',
        ]);
        
        $serverId = $request->input('server_id');
        $viewerCount = $request->input('viewer_count');
        $streams = $request->input('streams', []);
        
        Log::info('Edge server heartbeat', [
            'server_id' => $serverId,
            'viewer_count' => $viewerCount,
            'streams' => $streams,
        ]);
        
        // Find server by hostname or hetzner_id
        $server = Server::where('hostname', $serverId)
            ->orWhere('hetzner_id', $serverId)
            ->first();
            
        if (!$server) {
            // Try to find by container name for local development
            if (str_contains($serverId, 'docker')) {
                $server = Server::where('hetzner_id', 'manual')
                    ->where('type', \App\Enum\ServerTypeEnum::EDGE)
                    ->first();
            }
            
            if (!$server) {
                Log::warning('Unknown edge server in heartbeat', ['server_id' => $serverId]);
                return response()->json(['error' => 'Unknown server'], 404);
            }
        }
        
        // Update server viewer count
        $server->updateViewerCount($viewerCount);
        
        // Store stream-specific viewer counts in cache
        foreach ($streams as $streamSlug => $count) {
            Cache::put(
                "stream_viewers:{$streamSlug}:{$server->id}",
                $count,
                now()->addMinutes(2)
            );
        }
        
        // Calculate total viewers across all edges for each stream
        foreach ($streams as $streamSlug => $count) {
            $this->updateStreamViewerCount($streamSlug);
        }
        
        return response()->json([
            'status' => 'ok',
            'server' => [
                'id' => $server->id,
                'hostname' => $server->hostname,
            ],
        ]);
    }
    
    /**
     * Get or create a session for a viewer
     */
    private function getOrCreateSession($ip, $streamSlug, $edgeServerId, $userId = null)
    {
        // Generate session key - include user ID if available for unique sessions per user
        $sessionKey = $userId 
            ? "hls_session:user:{$userId}:{$streamSlug}:{$edgeServerId}"
            : "hls_session:{$ip}:{$streamSlug}:{$edgeServerId}";
        
        // Check cache for existing session
        $sessionId = Cache::get($sessionKey);
        
        if (!$sessionId) {
            // Generate new session ID
            $sessionId = Str::uuid()->toString();
            
            // Store in cache with 5 minute TTL (will be refreshed on each request)
            Cache::put($sessionKey, $sessionId, now()->addMinutes(5));
            
            // New session created (no logging for performance)
        } else {
            // Refresh TTL
            Cache::put($sessionKey, $sessionId, now()->addMinutes(5));
        }
        
        return $sessionId;
    }
    
    /**
     * Update total viewer count for a stream across all edges
     */
    private function updateStreamViewerCount($streamSlug)
    {
        // Get all edge servers
        $edges = Server::getActiveEdges();
        
        $totalViewers = 0;
        foreach ($edges as $edge) {
            $count = Cache::get("stream_viewers:{$streamSlug}:{$edge->id}", 0);
            $totalViewers += $count;
        }
        
        // Store total count
        Cache::put("stream_total_viewers:{$streamSlug}", $totalViewers, now()->addMinutes(2));
        
        Log::info('Stream viewer count updated', [
            'stream' => $streamSlug,
            'total_viewers' => $totalViewers,
            'edge_count' => $edges->count(),
        ]);
    }
    
}
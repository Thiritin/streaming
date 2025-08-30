<?php

namespace App\Http\Controllers\Api;

use App\Models\Source;
use App\Models\SourceUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HlsSessionController extends Controller
{
    /**
     * Validate HLS session for NGINX auth_request
     */
    public function auth(Request $request)
    {
        $sessionToken = $request->header('X-Session-Token') ?: $request->query('session');
        $originalUri = $request->header('X-Original-URI');
        $clientIp = $request->header('X-Real-IP') ?: $request->ip();
        
        // Extract stream name from URI
        $streamName = null;
        if ($originalUri && preg_match('/\/live\/([^\/\?]+)/', $originalUri, $matches)) {
            $streamName = str_replace(['_fhd', '_hd', '_sd', '_ld', '.m3u8', '.ts'], '', $matches[1]);
        }
        
        // Validate session token
        if (!$sessionToken || !$this->isValidSession($sessionToken, $clientIp, $streamName)) {
            return response('Unauthorized', 401);
        }
        
        // Update session activity
        Cache::put("hls_session:{$sessionToken}", [
            'ip' => $clientIp,
            'stream' => $streamName,
            'last_activity' => now(),
        ], now()->addMinutes(5));
        
        return response('OK', 200);
    }
    
    /**
     * Create new HLS session for a user
     */
    public function createSession(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        // Generate unique session token
        $sessionToken = Str::random(40);
        $clientIp = $request->ip();
        
        // Store session data
        Cache::put("hls_session:{$sessionToken}", [
            'user_id' => $user->id,
            'ip' => $clientIp,
            'created_at' => now(),
            'last_activity' => now(),
        ], now()->addHours(24));
        
        return response()->json([
            'session' => $sessionToken,
            'expires_in' => 86400, // 24 hours
        ]);
    }
    
    /**
     * Session start notification from tracker
     */
    public function sessionStart(Request $request)
    {
        // Validate API key
        if (!$this->validateApiKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->validate([
            'session' => 'required|string',
            'ip' => 'required|ip',
            'stream' => 'required|string',
            'timestamp' => 'required|date',
        ]);
        
        // Find source by stream name
        $source = Source::where('slug', $data['stream'])->first();
        if (!$source) {
            Log::warning('HLS session start for unknown stream', $data);
            return response()->json(['status' => 'ignored'], 200);
        }
        
        // Get user from session if available
        $sessionData = Cache::get("hls_session:{$data['session']}");
        $userId = $sessionData['user_id'] ?? null;
        
        // Create or update source_user record
        if ($userId) {
            $sourceUser = SourceUser::firstOrCreate(
                [
                    'source_id' => $source->id,
                    'user_id' => $userId,
                    'session_token' => $data['session'],
                ],
                [
                    'joined_at' => $data['timestamp'],
                    'ip_address' => $data['ip'],
                ]
            );
            
            // Update viewer count on associated shows
            foreach ($source->shows()->live()->get() as $show) {
                $show->updateViewerCount();
            }
            
            Log::info('HLS session started', [
                'session' => $data['session'],
                'user_id' => $userId,
                'source' => $source->slug,
            ]);
        }
        
        return response()->json(['status' => 'ok']);
    }
    
    /**
     * Session heartbeat from tracker
     */
    public function sessionHeartbeat(Request $request)
    {
        // Validate API key
        if (!$this->validateApiKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->validate([
            'session' => 'required|string',
            'stream' => 'required|string',
            'timestamp' => 'required|date',
            'segments_watched' => 'integer',
        ]);
        
        // Update session activity
        $sessionData = Cache::get("hls_session:{$data['session']}");
        if ($sessionData) {
            $sessionData['last_activity'] = now();
            $sessionData['segments_watched'] = $data['segments_watched'] ?? 0;
            Cache::put("hls_session:{$data['session']}", $sessionData, now()->addMinutes(5));
            
            // Update source_user if exists
            if (isset($sessionData['user_id'])) {
                SourceUser::where('session_token', $data['session'])
                    ->where('left_at', null)
                    ->update(['last_heartbeat' => now()]);
            }
        }
        
        return response()->json(['status' => 'ok']);
    }
    
    /**
     * Session end notification from tracker
     */
    public function sessionEnd(Request $request)
    {
        // Validate API key
        if (!$this->validateApiKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->validate([
            'session' => 'required|string',
            'ip' => 'required|ip',
            'stream' => 'required|string',
            'duration_seconds' => 'required|numeric',
            'segments_watched' => 'integer',
            'qualities_used' => 'array',
        ]);
        
        // Mark source_user as left
        $sourceUser = SourceUser::where('session_token', $data['session'])
            ->where('left_at', null)
            ->first();
            
        if ($sourceUser) {
            $sourceUser->update([
                'left_at' => now(),
                'duration_seconds' => $data['duration_seconds'],
                'metadata' => array_merge($sourceUser->metadata ?? [], [
                    'segments_watched' => $data['segments_watched'] ?? 0,
                    'qualities_used' => $data['qualities_used'] ?? [],
                ]),
            ]);
            
            // Update viewer count on associated shows
            $source = Source::where('slug', $data['stream'])->first();
            if ($source) {
                foreach ($source->shows()->live()->get() as $show) {
                    $show->updateViewerCount();
                }
            }
            
            Log::info('HLS session ended', [
                'session' => $data['session'],
                'user_id' => $sourceUser->user_id,
                'duration' => $data['duration_seconds'],
            ]);
        }
        
        // Clean up cache
        Cache::forget("hls_session:{$data['session']}");
        
        return response()->json(['status' => 'ok']);
    }
    
    /**
     * Check if session is valid
     */
    private function isValidSession(string $sessionToken, string $clientIp, ?string $streamName): bool
    {
        $sessionData = Cache::get("hls_session:{$sessionToken}");
        
        if (!$sessionData) {
            return false;
        }
        
        // Check IP match (optional, can be disabled for mobile networks)
        if (config('stream.validate_session_ip', false) && $sessionData['ip'] !== $clientIp) {
            Log::warning('Session IP mismatch', [
                'session' => $sessionToken,
                'expected' => $sessionData['ip'],
                'actual' => $clientIp,
            ]);
            return false;
        }
        
        // Check if session is not expired (last activity within 5 minutes)
        if (isset($sessionData['last_activity'])) {
            $lastActivity = $sessionData['last_activity'];
            if ($lastActivity instanceof \DateTime) {
                $lastActivity = \Carbon\Carbon::instance($lastActivity);
            }
            if (now()->diffInMinutes($lastActivity) > 5) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate API key from tracker
     */
    private function validateApiKey(Request $request): bool
    {
        $apiKey = $request->header('X-API-Key');
        $expectedKey = config('stream.hls_tracker_api_key');
        
        if (!$expectedKey) {
            // If no key configured, allow all requests (development)
            return true;
        }
        
        return $apiKey === $expectedKey;
    }
}
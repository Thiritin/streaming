<?php

namespace App\Http\Controllers\Api;

use App\Enum\SourceStatusEnum;
use App\Events\SourceStatusChangedEvent;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SrsCallbackController extends Controller
{
    /**
     * Handle SRS on_publish webhook for stream authentication
     * Called when a client wants to publish a stream
     */
    public function auth(Request $request)
    {
        // Log the incoming request for debugging
        Log::info('SRS auth webhook called', [
            'data' => $request->all(),
            'ip' => $request->ip(),
        ]);

        // SRS sends these parameters
        $app = $request->input('app');
        $stream = $request->input('stream');
        $tcUrl = $request->input('tcUrl');
        $param = $request->input('param'); // Query string from RTMP URL (e.g., "?secret=xyz")
        $clientIp = $request->input('ip'); // The actual client IP from SRS
        
        // Allow internal transcoding to publish to "live" app
        // Block external clients from publishing directly to "live" app
        if ($app === 'live') {
            // Check if this is an internal transcoding output (from localhost)
            if ($clientIp === '127.0.0.1' || $clientIp === '::1') {
                // This is internal transcoding from ffmpeg, allow it
                Log::info('Internal transcoding to live app allowed', [
                    'app' => $app,
                    'stream' => $stream,
                    'ip' => $clientIp,
                ]);
                
                // Allow internal transcoding without authentication
                return response()->json(['code' => 0]);
            }
            
            // External client trying to publish to live app - reject
            Log::warning('External publishing to live app rejected - use ingress app', [
                'app' => $app,
                'stream' => $stream,
                'ip' => $clientIp,
            ]);
            
            return response()->json(['code' => 403, 'msg' => 'Publishing to live app not allowed - use ingress app'], 403);
        }
        
        // Remove the leading '?' if present and parse parameters
        $param = ltrim($param, '?');
        parse_str($param, $params);
        
        // Check for 'secret' parameter (which is the stream_key)
        $streamKey = $params['secret'] ?? null;
        
        // Also check for shared_secret for server-to-server auth
        $sharedSecret = $params['shared_secret'] ?? null;
        
        // Get the server making the request (for edge->origin auth)
        $serverIp = $request->ip();
        
        // First check if this is a server-to-server forward (edge to origin)
        if ($sharedSecret) {
            $server = Server::where('shared_secret', $sharedSecret)
                ->where('status', \App\Enum\ServerStatusEnum::ACTIVE)
                ->first();
                
            if ($server) {
                Log::info('Server-to-server auth successful', [
                    'server_id' => $server->id,
                    'hostname' => $server->hostname,
                ]);
                
                // For server-to-server, still update the source status based on stream name
                $source = Source::where('slug', $stream)->first();
                if ($source) {
                    $previousStatus = $source->status->value;
                    $source->status = SourceStatusEnum::ONLINE;
                    $source->save();
                    
                    // Broadcast status change event
                    if ($previousStatus !== SourceStatusEnum::ONLINE->value) {
                        broadcast(new SourceStatusChangedEvent($source, $previousStatus));
                    }
                }
                
                return response()->json([
                    'code' => 0,
                    'server' => [
                        'id' => (string) $server->id,
                        'signature' => md5($server->id . ':' . $server->shared_secret),
                    ]
                ]);
            }
            
            Log::warning('Server-to-server auth failed - invalid shared_secret', [
                'ip' => $serverIp,
            ]);
            
            return response()->json(['code' => 403], 403);
        }
        
        // Regular source authentication
        if (!$streamKey) {
            Log::warning('No stream key provided in publish request', [
                'app' => $app,
                'stream' => $stream,
                'param' => $param,
            ]);
            
            return response()->json(['code' => 403], 403);
        }
        
        // Find source by slug (stream name)
        $source = Source::where('slug', $stream)->first();
            
        if (!$source) {
            Log::warning('No source found for stream', [
                'stream' => $stream,
            ]);
            
            return response()->json(['code' => 403], 403);
        }
        
        // Verify the stream_key matches (encrypted field will auto-decrypt on access)
        if ($source->stream_key !== $streamKey) {
            Log::warning('Invalid stream key for source', [
                'stream' => $stream,
                'source_id' => $source->id,
                'provided_key' => substr($streamKey, 0, 8) . '...',
            ]);
            
            return response()->json(['code' => 403], 403);
        }
        
        Log::info('Stream auth successful', [
            'source_id' => $source->id,
            'source_name' => $source->name,
            'app' => $app,
            'stream' => $stream,
            'previous_status' => $source->status->value,
        ]);
        
        // Update source status to online
        $previousStatus = $source->status->value;
        $source->status = SourceStatusEnum::ONLINE;
        $source->save();
        
        // Log recovery if coming from error state
        if ($previousStatus === SourceStatusEnum::ERROR->value) {
            Log::info('Source recovered from error state', [
                'source_id' => $source->id,
                'source_name' => $source->name,
            ]);
        }
        
        // Broadcast status change event
        if ($previousStatus !== SourceStatusEnum::ONLINE->value) {
            broadcast(new SourceStatusChangedEvent($source, $previousStatus));
        }
        
        // Return success with source info
        return response()->json([
            'code' => 0,
            'client' => [
                'id' => (string) $source->id,
                'signature' => md5($source->id . ':' . $streamKey),
            ]
        ]);
    }
    
    /**
     * Handle SRS on_play webhook
     * Called when a client wants to play a stream
     */
    public function play(Request $request)
    {
        // Log the play event
        Log::info('SRS play webhook called', [
            'data' => $request->all(),
        ]);
        
        // For now, allow all play requests
        // You could add viewer authentication here if needed
        
        return response()->json(['code' => 0]);
    }
    
    /**
     * Handle SRS on_stop webhook
     * Called when a client stops playing or publishing
     */
    public function stop(Request $request)
    {
        // Log the stop event
        Log::info('SRS stop webhook called', [
            'data' => $request->all(),
        ]);
        
        // Clean up any session data if needed
        
        return response()->json(['code' => 0]);
    }
    
    /**
     * Handle SRS on_unpublish webhook
     * Called when a stream stops publishing
     */
    public function unpublish(Request $request)
    {
        Log::info('SRS unpublish webhook called', [
            'data' => $request->all(),
        ]);
        
        $stream = $request->input('stream');
        $app = $request->input('app');
        
        // Find the source
        $source = Source::where('slug', $stream)->first();
        if ($source) {
            $previousStatus = $source->status->value;
            
            // Check if there's still a live show for this source
            $hasLiveShow = \App\Models\Show::where(function($query) use ($source) {
                    $query->where('source_id', $source->id)
                          ->orWhere('source_id', $source->slug);
                })
                ->where('status', 'live')
                ->exists();
            
            // If there's a live show, this is an unexpected disconnect (error)
            // If no live show, this is an expected shutdown (offline)
            if ($hasLiveShow) {
                $source->status = SourceStatusEnum::ERROR;
                Log::warning('Source disconnected while show is still live - setting to ERROR', [
                    'source_id' => $source->id,
                    'name' => $source->name,
                    'previous_status' => $previousStatus,
                ]);
            } else {
                $source->status = SourceStatusEnum::OFFLINE;
                Log::info('Source gracefully went offline - no live shows', [
                    'source_id' => $source->id,
                    'name' => $source->name,
                    'previous_status' => $previousStatus,
                ]);
            }
            
            $source->save();
            
            // Broadcast status change event
            if ($previousStatus !== $source->status->value) {
                broadcast(new SourceStatusChangedEvent($source, $previousStatus));
            }
        } else {
            Log::warning('No source found for stream', [
                'stream' => $stream,
            ]);
        }
        
        Log::info('Stream unpublish processed', [
            'app' => $app,
            'stream' => $stream,
            'final_status' => $source ? $source->status->value : 'unknown',
        ]);
        
        return response()->json(['code' => 0]);
    }
    
    /**
     * Handle SRS on_error webhook
     * Called when there's a stream error or connection interruption
     */
    public function error(Request $request)
    {
        Log::info('SRS error webhook called', [
            'data' => $request->all(),
        ]);
        
        $stream = $request->input('stream');
        $error = $request->input('error');
        $description = $request->input('description');
        
        // Update source status to error
        $source = Source::where('slug', $stream)->first();
        if ($source) {
            $previousStatus = $source->status->value;
            
            // Only set to error if currently online (to avoid overriding offline status)
            if ($source->status === SourceStatusEnum::ONLINE) {
                $source->status = SourceStatusEnum::ERROR;
                $source->save();
                
                Log::warning('Source status updated to error', [
                    'source_id' => $source->id,
                    'name' => $source->name,
                    'error' => $error,
                    'description' => $description,
                ]);
                
                // Broadcast status change event
                broadcast(new SourceStatusChangedEvent($source, $previousStatus));
            }
        } else {
            Log::warning('No source found for stream in error webhook', [
                'stream' => $stream,
                'error' => $error,
            ]);
        }
        
        return response()->json(['code' => 0]);
    }
    
    /**
     * Handle SRS on_hls webhook
     * Called when a client requests an HLS stream
     * This is where we authenticate HLS viewers
     */
    public function onHls(Request $request)
    {
        $data = $request->all();
        
        Log::info('SRS on_hls callback', $data);
        
        // Extract parameters from SRS callback
        $stream = $data['stream'] ?? '';
        $param = $data['param'] ?? '';
        $clientId = $data['client_id'] ?? '';
        $ip = $data['ip'] ?? '';
        
        // Parse query parameters from param field
        parse_str(ltrim($param, '?'), $params);
        
        // Check for streamkey or session_id
        $streamkey = $params['streamkey'] ?? $params['session_id'] ?? null;
        
        // Check if it's the internal system session
        if ($streamkey === config('stream.internal_session_id')) {
            Log::info('SRS auth bypassed for internal session', [
                'stream' => $stream,
                'client_id' => $clientId,
            ]);
            
            return response()->json(['code' => 0]);
        }
        
        // Validate streamkey if provided
        if ($streamkey) {
            $user = \App\Models\User::where('streamkey', $streamkey)->first();
            
            if ($user) {
                // Store user info with client_id for tracking
                \Illuminate\Support\Facades\Cache::put("srs_client:{$clientId}", [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'stream' => $stream,
                    'ip' => $ip,
                ], now()->addHours(2));
                
                Log::info('SRS HLS access granted', [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'stream' => $stream,
                    'client_id' => $clientId,
                ]);
                
                return response()->json(['code' => 0]);
            }
        }
        
        // Check if source exists and is online
        $source = Source::where('slug', $stream)->first();
        
        if (!$source) {
            Log::warning('Source not found for SRS HLS request', ['stream' => $stream]);
            return response()->json(['code' => 404, 'msg' => 'Stream not found']);
        }
        
        if ($source->status !== SourceStatusEnum::ONLINE) {
            Log::info('Source offline for SRS HLS request', [
                'stream' => $stream,
                'status' => $source->status->value,
            ]);
            return response()->json(['code' => 404, 'msg' => 'Stream offline']);
        }
        
        // If no auth but stream is online, allow access (public stream)
        // You might want to make this configurable per source
        Log::info('SRS HLS public access granted', [
            'stream' => $stream,
            'client_id' => $clientId,
            'ip' => $ip,
        ]);
        
        return response()->json(['code' => 0]);
    }
}
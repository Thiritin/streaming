<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\SourceUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SourceHeartbeatController extends Controller
{
    /**
     * Handle heartbeat for a source.
     */
    public function heartbeat(Request $request, Source $source)
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = Auth::user();

        // Find or create the current session for this user and source
        $session = SourceUser::firstOrCreate(
            [
                'source_id' => $source->id,
                'user_id' => $user->id,
                'left_at' => null, // Current session (not ended)
            ],
            [
                'joined_at' => now(),
                'last_heartbeat_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

        // Update heartbeat
        $session->heartbeat();

        // Clean up stale sessions (heartbeat older than 3 minutes - allowing for 2 missed heartbeats)
        SourceUser::where('source_id', $source->id)
            ->whereNull('left_at')
            ->where('last_heartbeat_at', '<', now()->subMinutes(3))
            ->update(['left_at' => now()]);

        // Get current stats
        $activeViewers = $source->activeViewers()->count();

        return response()->json([
            'success' => true,
            'active_viewers' => $activeViewers,
            'session_id' => $session->id,
            'watch_duration' => $session->watch_duration,
        ]);
    }
}

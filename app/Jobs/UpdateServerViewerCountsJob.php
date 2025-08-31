<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Models\SourceUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateServerViewerCountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle(): void
    {
        // Get viewer counts per server by joining source_users with users
        // Count users who are actively watching (left_at is null) and have a server assigned
        $viewerCounts = DB::table('source_users')
            ->join('users', 'source_users.user_id', '=', 'users.id')
            ->whereNull('source_users.left_at')
            ->whereNotNull('users.server_id')
            ->groupBy('users.server_id')
            ->select('users.server_id', DB::raw('COUNT(DISTINCT users.id) as viewer_count'))
            ->pluck('viewer_count', 'server_id');

        // Update each server with its viewer count
        foreach ($viewerCounts as $serverId => $count) {
            Server::where('id', $serverId)->update([
                'viewer_count' => $count,
                'last_heartbeat' => now(),
            ]);
            
            Log::debug('Updated server viewer count', [
                'server_id' => $serverId,
                'viewer_count' => $count,
            ]);
        }

        // Reset viewer count to 0 for servers with no active viewers
        Server::whereNotIn('id', $viewerCounts->keys()->toArray())
            ->where('type', \App\Enum\ServerTypeEnum::EDGE)
            ->where('status', \App\Enum\ServerStatusEnum::ACTIVE)
            ->update([
                'viewer_count' => 0,
                'last_heartbeat' => now(),
            ]);

        // Log summary
        $totalViewers = $viewerCounts->sum();
        $activeServers = $viewerCounts->count();
        
        Log::info('Server viewer counts updated', [
            'total_viewers' => $totalViewers,
            'active_servers' => $activeServers,
            'server_counts' => $viewerCounts->toArray(),
        ]);
    }
}
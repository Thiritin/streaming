<?php

namespace App\Jobs;

use App\Models\SourceUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupStaleViewerSessionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle(): void
    {
        // Clean up stale sessions across ALL sources
        // Mark as left if heartbeat is older than 3 minutes
        $staleCount = SourceUser::whereNull('left_at')
            ->where('last_heartbeat_at', '<', now()->subMinutes(3))
            ->update(['left_at' => now()]);

        if ($staleCount > 0) {
            Log::info('Cleaned up stale viewer sessions', [
                'count' => $staleCount,
                'threshold' => '3 minutes',
            ]);
        }

        // Also clean up very old sessions that somehow have no heartbeat at all
        // (joined more than 5 minutes ago with no heartbeat)
        $veryStaleCount = SourceUser::whereNull('left_at')
            ->whereNull('last_heartbeat_at')
            ->where('joined_at', '<', now()->subMinutes(5))
            ->update(['left_at' => now()]);

        if ($veryStaleCount > 0) {
            Log::warning('Cleaned up very stale viewer sessions with no heartbeat', [
                'count' => $veryStaleCount,
            ]);
        }
    }
}
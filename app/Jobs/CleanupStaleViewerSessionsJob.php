<?php

namespace App\Jobs;

use App\Models\SourceUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupStaleViewerSessionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Scheduled every minute, and it recomputes current state rather than
     * accumulating it - so a queued copy that has not run yet already does
     * everything a second one would.
     *
     * Without this the scheduler dispatches regardless of whether the last copy ever
     * ran. When Horizon's workers could not start (v0.4.0 to v0.4.3) that produced
     * 8,200 queued jobs in five hours, none of which were worth running by the time
     * they could be. `ShouldBeUnique` caps the standing backlog at one per class.
     *
     * `uniqueFor` is the lock's own expiry, a safety net for a job that dies without
     * releasing it; the lock is normally freed the moment the job finishes.
     */
    public int $uniqueFor = 300;

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

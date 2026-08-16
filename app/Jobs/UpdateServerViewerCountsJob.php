<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateServerViewerCountsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Scheduled every 30 seconds, and it recomputes current state rather than
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
        // Sessions carry their own edge now, so this is a group-by with no join.
        //
        // It used to join out to `users.server_id`, which meant a session could only be
        // counted if it belonged to a signed-in viewer. Guests were therefore invisible
        // to every edge's load, and since the guest branch of HlsController picks the
        // least loaded edge, they all went to the same one and nothing ever said so.
        //
        // COUNT(*) rather than COUNT(DISTINCT user): one row is one player pulling one
        // ladder, and capacity here is bandwidth. A viewer with two sources open costs
        // twice as much and should count twice.
        //
        // The heartbeat window matches SourceUser::scopeActive. Without it this counts
        // rows that CleanupStaleViewerSessionsJob has not reached yet, which inflates
        // every edge and is worse now that guests are in the table.
        $viewerCounts = DB::table('source_users')
            ->whereNull('left_at')
            ->whereNotNull('server_id')
            ->where('last_heartbeat_at', '>', now()->subMinutes(3))
            ->groupBy('server_id')
            ->select('server_id', DB::raw('COUNT(*) as viewer_count'))
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

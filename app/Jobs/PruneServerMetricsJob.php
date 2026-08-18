<?php

namespace App\Jobs;

use App\Models\ServerMetric;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * `server_metrics` gains a row per server per minute and nothing else removes them.
 * Retention is config, see stream.server.metrics_retention_days.
 */
class PruneServerMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct() {}

    public function handle(): void
    {
        $days = max(1, (int) config('stream.server.metrics_retention_days', 30));

        // Chunked by id rather than `delete ... limit`, which Postgres does not
        // accept: a month of samples for a dozen servers is half a million rows, and
        // one statement holding that many locks is a bad neighbour to the heartbeats
        // still arriving.
        $deleted = 0;

        do {
            $ids = ServerMetric::where('recorded_at', '<', now()->subDays($days))
                ->orderBy('id')
                ->limit(5000)
                ->pluck('id');

            $batch = $ids->isEmpty() ? 0 : ServerMetric::whereIn('id', $ids)->delete();

            $deleted += $batch;
        } while ($batch > 0);

        if ($deleted > 0) {
            Log::info('Pruned server metrics', ['deleted' => $deleted, 'retention_days' => $days]);
        }
    }
}

<?php

namespace App\Console;

use App\Jobs\CleanupStaleViewerSessionsJob;
use App\Jobs\FlushShowBoopsJob;
use App\Jobs\PruneDisplayScreensJob;
use App\Jobs\PruneServerMetricsJob;
use App\Jobs\SaveViewCountJob;
use App\Jobs\ScanArchiveStorageJob;
use App\Jobs\Server\ServerHealthCheckJob;
use App\Jobs\UpdateListenerCountJob;
use App\Jobs\UpdateServerViewerCountsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        $schedule->job(new UpdateListenerCountJob)->everyMinute();
        $schedule->job(new SaveViewCountJob)->everyMinute();

        // Boops are counted in the cache and banked here: one UPDATE and one
        // broadcast per show per tick, however hard the button is being mashed.
        // See App\Services\BoopCounter.
        $schedule->job(new FlushShowBoopsJob)->everyFiveSeconds();

        // Update server viewer counts based on active source_users
        $schedule->job(new UpdateServerViewerCountsJob)->everyThirtySeconds();

        // Clean up stale viewer sessions that haven't been active for 3+ minutes
        $schedule->job(new CleanupStaleViewerSessionsJob)->everyMinute();

        // Health check for edge servers every minute
        $schedule->job(new ServerHealthCheckJob)->everyMinute();

        // Capture thumbnails for live streams every minute
        $schedule->command('thumbnails:capture')->everyMinute();

        // Record viewer statistics for live shows every minute
        $schedule->command('statistics:record')->everyMinute();

        // Check auto mode shows every minute to start/end them based on schedule and source status
        $schedule->command('shows:check-auto-mode')->everyMinute();

        // Totals for the archive bucket, which the recordings page reads from the cache.
        // A full listing of a con-long archive is hundreds of requests and minutes of
        // wall clock, so it runs here rather than on a page load; hourly is far finer
        // than the rate at which "are we running out of room" changes its answer.
        $schedule->job(new ScanArchiveStorageJob)->hourly();

        // ----------------------------------------------------------------- upkeep
        //
        // The recurring jobs above are all `ShouldBeUnique`, so a queue that stops
        // draining can no longer accumulate thousands of copies of them. These three
        // keep the surrounding bookkeeping from growing without bound.

        // Horizon's metrics come from these samples; without them the dashboard's
        // throughput and runtime graphs are simply empty. `trim_snapshots` in
        // config/horizon.php decides how many are kept.
        $schedule->command('horizon:snapshot')->everyFiveMinutes();

        // `failed_jobs` is append-only and nothing ever removed from it. Two days is
        // longer than anyone waits before looking at a failure, and short enough that
        // the table stays small.
        $schedule->command('queue:prune-failed --hours=48')->daily();

        // Batched jobs record a row per batch, kept for the same reason and as long.
        $schedule->command('queue:prune-batches --hours=48')->daily();

        // System samples from every server's heartbeat, one row a minute each.
        $schedule->job(new PruneServerMetricsJob)->daily();

        // Screens that stopped polling a day ago and are not coming back.
        $schedule->job(new PruneDisplayScreensJob)->daily();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

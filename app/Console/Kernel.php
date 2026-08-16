<?php

namespace App\Console;

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
        $schedule->job(new \App\Jobs\UpdateListenerCountJob)->everyMinute();
        $schedule->job(new \App\Jobs\SaveViewCountJob)->everyMinute();
        $schedule->job(new \App\Jobs\ServerAssignmentJob)->everyFifteenSeconds();
        // Disabled: CleanUpInactiveServerAssignmentsJob - clients table has been dropped
        // $schedule->job(new \App\Jobs\CleanUpInactiveServerAssignmentsJob)->everyFiveMinutes();

        // Update server viewer counts based on active source_users
        $schedule->job(new \App\Jobs\UpdateServerViewerCountsJob)->everyThirtySeconds();

        // Clean up stale viewer sessions that haven't been active for 3+ minutes
        $schedule->job(new \App\Jobs\CleanupStaleViewerSessionsJob)->everyMinute();

        // Health check for edge servers every minute
        $schedule->job(new \App\Jobs\Server\ServerHealthCheckJob)->everyMinute();

        // Capture thumbnails for live streams every minute
        $schedule->command('thumbnails:capture')->everyMinute();

        // Record viewer statistics for live shows every minute
        $schedule->command('statistics:record')->everyMinute();

        // Check auto mode shows every minute to start/end them based on schedule and source status
        $schedule->command('shows:check-auto-mode')->everyMinute();

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

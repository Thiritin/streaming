<?php

namespace App\Console;

use App\Jobs\CleanupStaleViewerSessionsJob;
use App\Jobs\SaveViewCountJob;
use App\Jobs\Server\ServerHealthCheckJob;
use App\Jobs\ServerAssignmentJob;
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
        $schedule->job(new ServerAssignmentJob)->everyFifteenSeconds();
        // Disabled: CleanUpInactiveServerAssignmentsJob - clients table has been dropped
        // $schedule->job(new \App\Jobs\CleanUpInactiveServerAssignmentsJob)->everyFiveMinutes();

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

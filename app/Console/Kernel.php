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
        $schedule->job(new \App\Jobs\Server\ScalingJob)->everyMinute();
        $schedule->job(new \App\Jobs\ServerAssignmentJob)->everyFifteenSeconds();
        // Disabled: CleanUpInactiveServerAssignmentsJob - clients table has been dropped
        // $schedule->job(new \App\Jobs\CleanUpInactiveServerAssignmentsJob)->everyFiveMinutes();

        // Health check for edge servers every minute
        $schedule->job(new \App\Jobs\Server\ServerHealthCheckJob)->everyMinute();

        // Capture thumbnails for live streams every minute
        $schedule->command('thumbnails:capture')->everyMinute();
        
        // Record viewer statistics for live shows every minute
        $schedule->command('statistics:record')->everyMinute();
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
